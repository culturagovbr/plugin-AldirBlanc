<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Jobs\ResultadoCultJob;
use AldirBlanc\Services\ResultadoService;
use MapasCulturais\Entities\Job;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\User;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\BuildsPublishedFinalPhase;
use Tests\AldirBlanc\Traits\ConfiguresPlugin;
use Tests\AldirBlanc\Traits\IsolatesJobQueue;
use Tests\Traits\UserDirector;

/** Lista publicada mudou depois do envio: reenvia como retificado, coalescendo as alterações. */
class ResultadoResyncTest extends TestCase
{
    use UserDirector;
    use IsolatesJobQueue;
    use BuildsPublishedFinalPhase;
    use ConfiguresPlugin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearJobQueue();
    }

    /** Edital elegível, fase final publicada, resultado JÁ enviado e uma inscrição na fase. */
    private function cenarioEnviado(string $nome): array
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, $nome);
        $raiz = $this->raizElegivel($owner, $subsite);
        $fase = $this->faseFinal($raiz);
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, '53790');
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_RESULTADO_ID, '777');

        $this->app->disableAccessControl();
        $inscricao = new Registration();
        $inscricao->opportunity = $fase;
        $inscricao->owner = $owner->profile;
        $inscricao->save(true);
        $this->app->enableAccessControl();

        $this->clearJobQueue();

        return [$raiz, $fase, $inscricao];
    }

    private function jobsDeResultado(): array
    {
        return $this->app->repo(Job::class)->findBy(['type' => ResultadoCultJob::SLUG]);
    }

    private function dirtySince(int $faseId): string|false
    {
        $row = $this->app->em->getConnection()->fetchOne(
            'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => $faseId, 'key' => Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_DIRTY_SINCE]
        );

        return $row === false ? false : (string) $row;
    }

    /** A alteração roda com o formulário de resultado completo: é o que o gate do envio exige. */
    private function alteraStatus(Registration $inscricao): void
    {
        $this->comFormularioResultado(function () use ($inscricao) {
            $this->app->disableAccessControl();
            $inscricao->setStatusToApproved();
            $this->app->enableAccessControl();
        });
    }

    function testAlteracaoDeStatusAbreAJanelaEAgendaUmJob()
    {
        [, $fase, $inscricao] = $this->cenarioEnviado('Subsite Resync Status');

        $this->alteraStatus($inscricao);

        $jobs = $this->jobsDeResultado();
        $this->assertCount(1, $jobs, 'a primeira alteração da janela agenda o reenvio');
        $this->assertNotFalse($this->dirtySince((int) $fase->id), 'a janela nasce carimbada');
        $daquiUmMinuto = (new \DateTime('+1 minute'))->getTimestamp();
        $this->assertEqualsWithDelta($daquiUmMinuto, $jobs[0]->nextExecutionTimestamp->getTimestamp(), 5, 'debounce de 1 minuto');
    }

    function testCinquentaAlteracoesViramUmJobSo()
    {
        [, , $inscricao] = $this->cenarioEnviado('Subsite Resync Coalesce');

        $this->comFormularioResultado(function () use ($inscricao) {
            $this->app->disableAccessControl();
            for ($i = 0; $i < 50; $i++) {
                $i % 2 === 0 ? $inscricao->setStatusToApproved() : $inscricao->setStatusToWaitlist();
            }
            $this->app->enableAccessControl();
        });

        $this->assertCount(1, $this->jobsDeResultado(), 'N alterações não podem virar N jobs nem N requisições');
    }

    function testFaseNaoPublicadaNaoAgenda()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Resync Nao Publicada');
        $raiz = $this->raizElegivel($owner, $subsite);
        $fase = $this->faseFinal($raiz, publicada: false);
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, '53790');
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_RESULTADO_ID, '777');

        $this->app->disableAccessControl();
        $inscricao = new Registration();
        $inscricao->opportunity = $fase;
        $inscricao->owner = $owner->profile;
        $inscricao->save(true);
        $this->app->enableAccessControl();
        $this->clearJobQueue();

        $this->alteraStatus($inscricao);

        $this->assertCount(0, $this->jobsDeResultado());
    }

    function testEditalSemResultadoEnviadoNaoAgenda()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Resync Nao Enviado');
        $raiz = $this->raizElegivel($owner, $subsite);
        $fase = $this->faseFinal($raiz);
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, '53790');

        $this->app->disableAccessControl();
        $inscricao = new Registration();
        $inscricao->opportunity = $fase;
        $inscricao->owner = $owner->profile;
        $inscricao->save(true);
        $this->app->enableAccessControl();
        $this->clearJobQueue();

        $this->alteraStatus($inscricao);

        $this->assertCount(0, $this->jobsDeResultado(), 'sem primeiro envio não há o que retificar: o gatilho é o 1º envio');
    }

    function testRemocaoDeInscricaoMarcaEEnfileiraForaDoFlush()
    {
        [, $fase, $inscricao] = $this->cenarioEnviado('Subsite Resync Remocao');

        $this->app->disableAccessControl();
        $inscricao->delete(true);
        $this->app->enableAccessControl();

        $this->assertCount(0, $this->jobsDeResultado(), 'dentro do flush do postRemove nada é enfileirado');

        $this->comFormularioResultado(fn() => (new ResultadoService())->flushDeferredResultadoResyncs());

        $this->assertCount(1, $this->jobsDeResultado(), 'o reenvio adiado sai depois do flush');
        $this->assertNotFalse($this->dirtySince((int) $fase->id));
    }

    function testNoTetoComJobPendenteNaoRemarca()
    {
        [, $fase, $inscricao] = $this->cenarioEnviado('Subsite Resync Teto');

        $this->alteraStatus($inscricao);

        // Sentinela direto na linha do job: remarcação a substituiria; leitura por SQL evita a identity map.
        $conn = $this->app->em->getConnection();
        $conn->executeStatement(
            "UPDATE job SET next_execution_timestamp = '2099-01-01 00:00:00' WHERE name = :name",
            ['name' => ResultadoCultJob::SLUG]
        );

        // Janela aberta há mais que o teto: a alteração nova não pode empurrar o job para sempre.
        $conn->executeStatement(
            'UPDATE opportunity_meta SET value = :value WHERE object_id = :id AND key = :key',
            ['value' => (new \DateTime('-11 minutes'))->format('Y-m-d H:i:s'), 'id' => (int) $fase->id, 'key' => Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_DIRTY_SINCE]
        );

        $this->comFormularioResultado(function () use ($inscricao) {
            $this->app->disableAccessControl();
            $inscricao->setStatusToWaitlist();
            $this->app->enableAccessControl();
        });

        $agendadoPara = $conn->fetchOne('SELECT next_execution_timestamp FROM job WHERE name = :name', ['name' => ResultadoCultJob::SLUG]);
        $this->assertSame('2099-01-01 00:00:00', $agendadoPara, 'no teto, o job roda no horário já agendado');
    }

    function testCarimboOrfaoAcimaDoTetoReiniciaAJanela()
    {
        [, $fase, $inscricao] = $this->cenarioEnviado('Subsite Resync Orfao');

        // Carimbo velho sem nenhum job pendente: a janela anterior morreu sem limpeza.
        $this->gravaMeta((int) $fase->id, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_DIRTY_SINCE, (new \DateTime('-11 minutes'))->format('Y-m-d H:i:s'));

        $this->alteraStatus($inscricao);

        $this->assertCount(1, $this->jobsDeResultado(), 'carimbo órfão não pode engolir alterações para sempre');
        $novoCarimbo = $this->dirtySince((int) $fase->id);
        $this->assertGreaterThan((new \DateTime('-1 minute'))->format('Y-m-d H:i:s'), $novoCarimbo, 'a janela recomeça agora');
    }

    function testAlteracaoComFormularioIncompletoCarimbaMasNaoAgenda()
    {
        [, $fase, $inscricao] = $this->cenarioEnviado('Subsite Resync Incompleto');

        $estruturaComObrigatoria = ['id_formulario' => 110, 'id_formulario_versao' => 1, 'versao' => 1, 'secoes' => [['etapas' => [['questoes' => [
            ['id_questao' => 52, 'nome' => 'Comentários', 'tipo_questao' => 'textarea', 'requerida' => true],
        ]]]]]];

        $this->comFormularioResultado(function () use ($inscricao) {
            $this->app->disableAccessControl();
            $inscricao->setStatusToApproved();
            $this->app->enableAccessControl();
        }, $estruturaComObrigatoria);

        $this->assertCount(0, $this->jobsDeResultado(), 'sem formulário completo a mudança não vira envio');
        $this->assertNotFalse($this->dirtySince((int) $fase->id), 'a alteração fica carimbada para quando o formulário completar');
    }

    function testExecucaoDoJobLimpaOCarimboEAlteracaoNovaAbreOutraRodada()
    {
        [$raiz, $fase, $inscricao] = $this->cenarioEnviado('Subsite Resync Pos Execucao');

        $this->alteraStatus($inscricao);
        $this->assertNotFalse($this->dirtySince((int) $fase->id));

        // O job roda (cai no envio real simulado? não — sem provider dublê o envio falha e não importa:
        // a limpeza do carimbo acontece antes do envio, em qualquer desfecho).
        $this->app->executeJob('2100-01-01 00:00');

        $this->assertFalse($this->dirtySince((int) $fase->id), 'o job rodando fecha a janela');

        $this->clearJobQueue();
        $this->alteraStatus($inscricao);

        $this->assertCount(1, $this->jobsDeResultado(), 'alteração após a execução abre a rodada seguinte');
        $this->assertNotFalse($this->dirtySince((int) $fase->id));
    }
}
