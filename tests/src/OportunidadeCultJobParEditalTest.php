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
 * Captura do id_par_edital ecoado pelo CultBR no envio de oportunidade,
 * persistido como metadado cultBrParEditalId da oportunidade.
 */
class OportunidadeCultJobParEditalTest extends TestCase
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
    private function parEditalId(int $opportunityId): string|false
    {
        $row = $this->app->em->getConnection()->fetchAssociative(
            'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => $opportunityId, 'key' => Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID]
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

    function testRespostaComIdGravaOMetadado()
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());

        $this->enviarComResposta($opp, '{"id_par_edital":1249}');

        $this->assertSame('1249', $this->parEditalId($opp->id), 'o id ecoado pelo CultBR deve virar metadado');
    }

    function testIdComoStringNumericaTambemGrava()
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());

        $this->enviarComResposta($opp, '{"id_par_edital":"1249"}');

        $this->assertSame('1249', $this->parEditalId($opp->id), 'id numérico vindo como string também é capturado');
    }

    function testIdZeroNaoGrava()
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());

        $this->enviarComResposta($opp, '{"id_par_edital":0}');

        $this->assertFalse($this->parEditalId($opp->id), 'id zero não existe no PAR e não pode ser gravado');
    }

    function testRespostaSemAChaveNaoGravaENaoFalha()
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());

        $this->enviarComResposta($opp, '{"titulo":"sem o id"}');

        $this->assertFalse($this->parEditalId($opp->id), 'sem a chave na resposta, nada a gravar');
        $this->assertCount(0, $this->app->repo('Job')->findAll(), 'a captura ausente não pode falhar o envio nem gerar retry');
    }

    function testRespostaNaoJsonNaoGravaENaoFalha()
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());

        $this->enviarComResposta($opp, '<html>erro upstream</html>');

        $this->assertFalse($this->parEditalId($opp->id), 'resposta não-JSON não tem id a extrair');
        $this->assertCount(0, $this->app->repo('Job')->findAll(), 'resposta ilegível não pode falhar o envio nem gerar retry');
    }

    function testReenvioAtualizaOValorExistente()
    {
        $opp = $this->createOpportunity($this->userDirector->createUser());

        $this->enviarComResposta($opp, '{"id_par_edital":1249}');
        $this->enviarComResposta($opp, '{"id_par_edital":53790}');

        $rows = $this->app->em->getConnection()->fetchAllAssociative(
            'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => $opp->id, 'key' => Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID]
        );
        $this->assertCount(1, $rows, 'reenvio substitui o valor, nunca duplica a linha');
        $this->assertSame('53790', $rows[0]['value'], 'o metadado guarda o id do último envio aceito');
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

        $this->assertFalse($this->parEditalId($opp->id), 'id ecoado em envio com erro não é confiável');
    }
}
