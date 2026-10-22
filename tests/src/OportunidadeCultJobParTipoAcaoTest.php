<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Enum\SendResult;
use MapasCulturais\Entities\Opportunity;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\IsolatesJobQueue;
use Tests\AldirBlanc\Traits\SendsOpportunityThroughJob;
use Tests\Traits\UserDirector;

/**
 * Captura do tipo de ação (acao.id_par_tipo_acao) do mesmo eco do envio de oportunidade,
 * persistido como metadado cultBrParTipoAcaoId para montar o formulário sem HTTP.
 */
class OportunidadeCultJobParTipoAcaoTest extends TestCase
{
    use UserDirector;
    use IsolatesJobQueue;
    use SendsOpportunityThroughJob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearJobQueue();
    }

    /** @return string|false o valor, ou false quando a chave não existe */
    private function tipoAcao(int $opportunityId): string|false
    {
        $row = $this->app->em->getConnection()->fetchAssociative(
            'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => $opportunityId, 'key' => Controller::OPPORTUNITY_META_CULT_BR_PAR_TIPO_ACAO_ID]
        );

        return $row === false ? false : (string) $row['value'];
    }

    private function enviarComResposta(Opportunity $opp, ?string $response): void
    {
        $this->comProvedorDuble(
            fn() => $this->desfecho(['response' => $response]),
            function () use ($opp) {
                $this->enqueueUpdateJob($opp);
                $this->processJobs(number_of_jobs: 1);
            }
        );
    }

    function testRespostaComTipoDeAcaoGravaOMetadado()
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());

        $this->enviarComResposta($opp, '{"id_par_edital":1249,"acao":{"id_par_tipo_acao":12}}');

        $this->assertSame('12', $this->tipoAcao($opp->id), 'o tipo de ação ecoado no bloco acao vira metadado');
    }

    function testRespostaSemBlocoAcaoNaoGravaENaoFalha()
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());

        $this->enviarComResposta($opp, '{"id_par_edital":1249}');

        $this->assertFalse($this->tipoAcao($opp->id), 'sem o bloco acao não há tipo a gravar');
        $this->assertCount(0, $this->app->repo('Job')->findAll(), 'a captura ausente não pode falhar o envio nem gerar retry');
    }

    function testTipoZeroNaoGrava()
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());

        $this->enviarComResposta($opp, '{"acao":{"id_par_tipo_acao":0}}');

        $this->assertFalse($this->tipoAcao($opp->id), 'tipo zero não existe no PAR e não pode ser gravado');
    }

    function testRespostaNaoJsonNaoGravaENaoFalha()
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());

        $this->enviarComResposta($opp, '<html>erro upstream</html>');

        $this->assertFalse($this->tipoAcao($opp->id), 'resposta não-JSON não tem tipo a extrair');
        $this->assertCount(0, $this->app->repo('Job')->findAll(), 'resposta ilegível não pode falhar o envio nem gerar retry');
    }

    function testReenvioAtualizaOValorExistente()
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());

        $this->enviarComResposta($opp, '{"acao":{"id_par_tipo_acao":1}}');
        $this->enviarComResposta($opp, '{"acao":{"id_par_tipo_acao":14}}');

        $rows = $this->app->em->getConnection()->fetchAllAssociative(
            'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => $opp->id, 'key' => Controller::OPPORTUNITY_META_CULT_BR_PAR_TIPO_ACAO_ID]
        );
        $this->assertCount(1, $rows, 'reenvio substitui o valor, nunca duplica a linha');
        $this->assertSame('14', $rows[0]['value'], 'o metadado guarda o tipo do último envio aceito');
    }

    function testEnvioComErroNaoGrava()
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());

        $this->comProvedorDuble(
            fn() => $this->desfecho(['result' => SendResult::Error, 'httpStatus' => 502]),
            function () use ($opp) {
                $this->enqueueUpdateJob($opp);
                $this->processJobs(number_of_jobs: 1);
            }
        );

        $this->assertFalse($this->tipoAcao($opp->id), 'tipo ecoado em envio com erro não é confiável');
    }
}
