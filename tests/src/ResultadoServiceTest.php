<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Plugin;
use AldirBlanc\Services\ResultadoService;
use MapasCulturais\Entities\Opportunity;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\FakeIntegrationProvider;
use Tests\AldirBlanc\Traits\BuildsContempladas;
use Tests\AldirBlanc\Traits\BuildsPublishedFinalPhase;
use Tests\AldirBlanc\Traits\ConfiguresPlugin;
use Tests\Traits\UserDirector;

/** Régua e payload do resultado: a fase final publicada decide, nunca o flag da raiz. */
class ResultadoServiceTest extends TestCase
{
    use UserDirector;
    use BuildsPublishedFinalPhase;
    use BuildsContempladas;
    use ConfiguresPlugin;

    private function service(): ResultadoService
    {
        return new ResultadoService();
    }

    function testFaseFinalPublicadaDeRaizCompletaEhElegivel()
    {
        [, $fase] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->assertTrue($this->service()->isEligibleForResultSync($fase));
    }

    function testFaseFinalNaoPublicadaNaoEhElegivel()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Nao Publicada');
        $raiz = $this->raizElegivel($owner, $subsite);
        $fase = $this->faseFinal($raiz, publicada: false);
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, '53790');

        $this->assertFalse($this->service()->isEligibleForResultSync($fase));
    }

    /** Fase intermediária e fase de recurso caem neste ramo: sem isLastPhase não há envio. */
    function testFaseQueNaoEhAFinalNaoEhElegivel()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Intermediaria');
        $raiz = $this->raizElegivel($owner, $subsite);
        $fase = $this->faseFinal($raiz);
        $fase->setMetadata('isLastPhase', false);
        $this->app->disableAccessControl();
        $fase->save(true);
        $this->app->enableAccessControl();
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, '53790');

        $this->assertFalse($this->service()->isEligibleForResultSync($fase));
    }

    function testRaizSemIdDoParNaoEhElegivel()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Sem Par Id');
        $raiz = $this->raizElegivel($owner, $subsite);
        $fase = $this->faseFinal($raiz);

        $this->assertFalse($this->service()->isEligibleForResultSync($fase));
    }

    function testRaizReprovadaNaReguaDaFase1NaoEhElegivel()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Sem Ente');
        $raiz = $this->raizElegivel($owner, $subsite);
        $fase = $this->faseFinal($raiz);
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, '53790');

        $this->app->disableAccessControl();
        $raiz->setMetadata('federativeEntityId', '');
        $raiz->save(true);
        $this->app->enableAccessControl();

        $this->assertFalse($this->service()->isEligibleForResultSync($fase));
    }

    function testPrimeiroEnvioMontaFinalPublicadoComADataDaFase()
    {
        [, $fase] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->comFormularioResultado(function () use ($fase) {
            $payload = $this->service()->buildResultado($fase)->toArray();

            // Literais de propósito: os valores são contrato do PAR, e comparar com o enum seria tautologia.
            $this->assertSame(2, $payload['id_tipo']);
            $this->assertSame(3, $payload['id_status']);
            $this->assertStringStartsWith('2026-10-10T12:00:00', $payload['data_publicacao'], 'o final carrega a data da publicação no Mapas');
        });
    }

    /** Contemplada com o mínimo que o validador exige; sem categoria, fica sem valor e segura a planilha. */
    private function contempladaCompleta(Opportunity $raiz, Opportunity $fase, bool $semCategoria = false): void
    {
        $this->app->disableAccessControl();
        $raiz->registrationRanges = [['label' => 'Faixa A', 'value' => '15000']];
        $raiz->save(true);
        $this->app->enableAccessControl();

        $this->contempladaComDocumento($raiz, $fase, '111.444.777-35', 'Pessoa Física', $semCategoria ? '' : 'Faixa A');
    }

    function testPlanilhaEntraNoCorpoQuandoAsContempladasEstaoCompletas()
    {
        [$raiz, $fase] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->contempladaCompleta($raiz, $fase);

        $this->comFormularioResultado(function () use ($fase) {
            $payload = $this->service()->buildResultado($fase)->toArray();

            $this->assertSame('acao_cultural_realizada', $payload['tipo_planilha']);
            $this->assertCount(1, $payload['linhas']);
            $this->assertSame('15000.00', $payload['linhas'][0]['valor_recebido_pnab']);
        });
    }

    function testContempladaIncompletaDeixaOResultadoSairSemPlanilha()
    {
        [$raiz, $fase] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->contempladaCompleta($raiz, $fase);
        $this->contempladaCompleta($raiz, $fase, semCategoria: true);

        $this->comFormularioResultado(function () use ($fase) {
            $payload = $this->service()->buildResultado($fase)->toArray();

            $this->assertArrayNotHasKey('linhas', $payload, 'o resultado publica, e a planilha espera o gestor completar a grade');
            $this->assertArrayNotHasKey('tipo_planilha', $payload);
        });
    }

    function testComResultadoVigenteMontaRetificadoDatadoDaRetificacao()
    {
        [$raiz, $fase] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_RESULTADO_ID, '777');

        $this->comFormularioResultado(function () use ($fase) {
            $payload = $this->service()->buildResultado($fase)->toArray();

            $this->assertSame(3, $payload['id_tipo']);
            $this->assertSame(3, $payload['id_status']);
            $this->assertStringNotContainsString('2026-10-10', $payload['data_publicacao'], 'a data original dataria o retificado antes de ele existir');
        });
    }

    function testPublicacaoSemCarimboCaiParaOInstanteDoEnvio()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Sem Carimbo');
        $raiz = $this->raizElegivel($owner, $subsite);
        $fase = $this->faseFinal($raiz, publicada: true, publicadaEm: null);
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, '53790');

        $this->comFormularioResultado(function () use ($fase) {
            $payload = $this->service()->buildResultado($fase)->toArray();

            $this->assertNotNull($payload['data_publicacao'], 'resultado publicado nunca viaja sem data');
        });
    }

    function testPayloadLevaRespostasSalvasAutomaticasEDataDePublicacao()
    {
        [$raiz, $fase] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_FORM, json_encode([
            'id_formulario' => 110, 'id_formulario_versao' => 1, 'versao' => 1,
            'respostas' => [51 => 'Comentário salvo pelo gestor.', 56 => 'Resposta sem endereço no PAR.', 59 => ['heteroidentificacao']],
        ]));

        $estrutura = ['id_formulario' => 110, 'id_formulario_versao' => 1, 'versao' => 1, 'secoes' => [['etapas' => [['questoes' => [
            ['id_questao' => 50, 'id_questao_etapa' => 950, 'nome' => 'Total de inscritos', 'tipo_questao' => 'número', 'requerida' => true, 'tag' => 'total_inscritos_1_1'],
            ['id_questao' => 51, 'id_questao_etapa' => 951, 'nome' => 'Comentários', 'tipo_questao' => 'textarea', 'requerida' => true],
            ['id_questao' => 54, 'id_questao_etapa' => 954, 'nome' => 'Data de publicação', 'tipo_questao' => 'data', 'requerida' => true, 'tag' => 'data_publicacao_1_1'],
            ['id_questao' => 55, 'id_questao_etapa' => 955, 'nome' => 'Campo nunca respondido', 'tipo_questao' => 'textarea', 'requerida' => false],
            ['id_questao' => 56, 'nome' => 'Questão sem id de etapa', 'tipo_questao' => 'textarea', 'requerida' => false],
            ['id_questao' => 59, 'id_questao_etapa' => 959, 'nome' => 'Procedimentos de cotas', 'tipo_questao' => 'múltipla escolha', 'requerida' => true, 'opcao' => ['itens' => [
                ['valor' => 'heteroidentificacao', 'label' => 'Heteroidentificação para pessoas negras'],
                ['valor' => 'carta_consubstanciada', 'label' => 'Solicitação de carta consubstanciada'],
            ]]],
        ]]]]]];

        $this->comFormularioResultado(function () use ($fase) {
            $respostas = $this->service()->buildResultado($fase)->toArray()['respostas'];

            // O envio endereça pelo id_questao_etapa; o salvamento interno segue por id_questao.
            $porQuestao = array_column($respostas, 'resposta', 'id_par_questao');
            $this->assertSame(0, $porQuestao[950]['resposta'], 'a contagem automática do edital entra no envio');
            $this->assertSame('Comentário salvo pelo gestor.', $porQuestao[951]['resposta']);
            $this->assertSame('2026-10-10', $porQuestao[954]['resposta'], 'a data de publicação viaja só como Y-m-d, sem hora/fuso');
            $this->assertSame(['Heteroidentificação para pessoas negras'], $porQuestao[959]['resposta'], 'múltipla escolha viaja com o label, não o slug salvo');
            $this->assertArrayNotHasKey(955, $porQuestao, 'questão sem resposta não viaja');
            $this->assertArrayNotHasKey(0, $porQuestao, 'questão sem id_questao_etapa não viaja');
        }, $estrutura);
    }

    private function jobsDeResultado(): array
    {
        return $this->app->repo('Job')->findBy(['type' => \AldirBlanc\Jobs\ResultadoCultJob::SLUG]);
    }

    function testCompletudeIndisponivelNaoEnfileira()
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        // O provedor da Gestão não serve o formulário: sem certeza da completude, nada é enfileirado.
        $this->comConfigDoPlugin(
            function (array $config) {
                $config['client']['provider'] = 'gestao';

                return $config;
            },
            function () use ($raiz) {
                Plugin::getInstance()->resetIntegrationProvider();

                try {
                    $this->service()->enqueueResultadoSync($raiz);
                } finally {
                    Plugin::getInstance()->resetIntegrationProvider();
                }
            },
        );

        $this->assertCount(0, $this->jobsDeResultado(), 'na dúvida o envio não sai — fail-safe do gate');
    }

    function testEditalSemFormularioNoParNaoEnfileira()
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->comFormularioResultado(function () use ($raiz) {
            FakeIntegrationProvider::$aoBuscarTipoAcao = fn() => null;

            $this->service()->enqueueResultadoSync($raiz);

            $this->assertCount(0, $this->jobsDeResultado(), 'sem formulário no PAR a integração não faz nada');
        });
    }

    function testPersistenciaDoIdVigenteSubstituiSemDuplicar()
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $raizId = (int) $raiz->id;
        $service = $this->service();

        $service->persistParEditalResultadoId($raizId, 777);
        $service->persistParEditalResultadoId($raizId, 888);

        $rows = $this->app->em->getConnection()->fetchAllAssociative(
            'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => $raizId, 'key' => Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_RESULTADO_ID]
        );
        $this->assertCount(1, $rows, 'o metadado guarda o VIGENTE: substitui, nunca duplica');
        $this->assertSame('888', $rows[0]['value']);
        $this->assertSame(888, $service->parEditalResultadoId($raizId));
    }
}
