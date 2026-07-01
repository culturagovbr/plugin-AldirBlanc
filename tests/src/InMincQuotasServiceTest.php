<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Services\InMincQuotasService;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\User;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

class InMincQuotasServiceTest extends TestCase
{
    use UserDirector;

    private function createOpportunity(User $user): Opportunity
    {
        $this->login($user);
        $this->app->disableAccessControl();
        $class = $user->profile->opportunityClassName;
        $opp = new $class();
        $opp->owner = $user->profile;
        $opp->ownerEntity = $user->profile;
        $opp->name = 'Oportunidade Cotas Test';
        $opp->shortDescription = 'desc';
        $opp->status = Opportunity::STATUS_DRAFT;
        $opp->save(true);
        $this->app->enableAccessControl();
        return $opp;
    }

    /** [vagas_negras, vagas_indigenas, vagas_pcd, vagas_ampla] com valorDestinado=0. */
    private function baseQuotas(int $negras, int $indigenas, int $pcd, int $ampla): array
    {
        return [
            ['vagas' => $negras,   'valorDestinado' => 0],
            ['vagas' => $indigenas,'valorDestinado' => 0],
            ['vagas' => $pcd,      'valorDestinado' => 0],
            ['vagas' => $ampla,    'valorDestinado' => 0],
        ];
    }

    // ===== roundQuota =====

    function testRoundQuotaFracaoMenorQueMeioArredondaParaBaixo()
    {
        $this->assertSame(2, InMincQuotasService::roundQuota(2.4));
    }

    function testRoundQuotaFracaoExatamenteMeioArredondaParaCima()
    {
        $this->assertSame(3, InMincQuotasService::roundQuota(2.5));
    }

    function testRoundQuotaFracaoMaiorQueMeioArredondaParaCima()
    {
        $this->assertSame(3, InMincQuotasService::roundQuota(2.6));
    }

    function testRoundQuotaValorInteiroNaoAltera()
    {
        $this->assertSame(3, InMincQuotasService::roundQuota(3.0));
    }

    function testRoundQuotaZeroRetornaZero()
    {
        $this->assertSame(0, InMincQuotasService::roundQuota(0.0));
    }

    // ===== getNormalMinimums =====

    function testGetNormalMinimumsComTotalZeroRetornaZeros()
    {
        $this->assertSame([0, 0, 0], InMincQuotasService::getNormalMinimums(0));
    }

    function testGetNormalMinimumsComTotalNegativoRetornaZeros()
    {
        $this->assertSame([0, 0, 0], InMincQuotasService::getNormalMinimums(-5));
    }

    function testGetNormalMinimumsComTotal100RetornaMinimosExatos()
    {
        $this->assertSame([25, 10, 5], InMincQuotasService::getNormalMinimums(100));
    }

    function testGetNormalMinimumsComTotal10ArredondaFracoesCorretamente()
    {
        // 10*0.25=2.5→3, 10*0.10=1.0→1, 10*0.05=0.5→1
        $this->assertSame([3, 1, 1], InMincQuotasService::getNormalMinimums(10));
    }

    function testGetNormalMinimumsComTotal4FracoesAbaixoDeMeioArredondamParaBaixo()
    {
        // 4*0.25=1.0→1, 4*0.10=0.4→0, 4*0.05=0.2→0
        $this->assertSame([1, 0, 0], InMincQuotasService::getNormalMinimums(4));
    }

    // ===== getExceptionCategoryMinimums =====

    function testGetExceptionCategoryMinimumsComTotalZeroRetornaZeros()
    {
        $this->assertSame([0, 0, 0], InMincQuotasService::getExceptionCategoryMinimums(0));
    }

    function testGetExceptionCategoryMinimumsComTotal100PcdUsaDezPorCento()
    {
        // PCD_EXCEPTIONAL=10%, não os 5% da regra geral
        $this->assertSame([25, 10, 10], InMincQuotasService::getExceptionCategoryMinimums(100));
    }

    function testGetExceptionCategoryMinimumsComTotal10ConsistenteComPcdExcepcional()
    {
        // 10*0.25=2.5→3, 10*0.10=1.0→1, 10*0.10=1.0→1
        $this->assertSame([3, 1, 1], InMincQuotasService::getExceptionCategoryMinimums(10));
    }

    // ===== validateQuotasReservation =====

    function testValidateQuotasNaoPrimeiraFaseRetornaFalse()
    {
        $user = $this->userDirector->createUser();
        $parent = $this->createOpportunity($user);

        $this->login($user);
        $this->app->disableAccessControl();
        $class = $user->profile->opportunityClassName;
        $phase = new $class();
        $phase->owner = $user->profile;
        $phase->ownerEntity = $user->profile;
        $phase->parent = $parent;
        $phase->name = 'Fase 2';
        $phase->shortDescription = 'fase';
        $phase->status = Opportunity::STATUS_DRAFT;
        $phase->save(true);
        $this->app->enableAccessControl();

        $result = InMincQuotasService::validateQuotasReservation($phase, []);

        $this->assertFalse($result, 'Oportunidade que não é primeira fase deve retornar false sem validar cotas');
    }

    function testValidateQuotasMenosDe4CotasRetornaErro()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->createOpportunity($user);

        $result = InMincQuotasService::validateQuotasReservation($opp, [
            'reservaVagasCotas' => [
                ['vagas' => 25, 'valorDestinado' => 0],
                ['vagas' => 10, 'valorDestinado' => 0],
                // faltam [2] e [3]
            ],
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('reservaVagasCotas', $result);
    }

    function testValidateQuotasTodasLawCotasNaoAplicavelRetornaFalse()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->createOpportunity($user);

        $result = InMincQuotasService::validateQuotasReservation($opp, [
            'reservaVagasCotas' => [
                ['naoAplicavel' => true, 'vagas' => 0, 'valorDestinado' => 0],
                ['naoAplicavel' => true, 'vagas' => 0, 'valorDestinado' => 0],
                ['naoAplicavel' => true, 'vagas' => 0, 'valorDestinado' => 0],
                ['vagas' => 10, 'valorDestinado' => 0],
            ],
        ]);

        $this->assertFalse($result, 'Quando todas as cotas da lei são naoAplicavel, deve retornar false (sem erro)');
    }

    function testValidateQuotasNaoAplicavelComVagasMaioresQueZeroRetornaErro()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->createOpportunity($user);

        $result = InMincQuotasService::validateQuotasReservation($opp, [
            'reservaVagasCotas' => [
                ['naoAplicavel' => true, 'vagas' => 1, 'valorDestinado' => 0],
                ['vagas' => 5, 'valorDestinado' => 0],
                ['vagas' => 2, 'valorDestinado' => 0],
                ['vagas' => 3, 'valorDestinado' => 0],
            ],
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('reservaVagasCotas', $result);
    }

    function testValidateQuotasCampoVagasAusenteRetornaErro()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->createOpportunity($user);

        $result = InMincQuotasService::validateQuotasReservation($opp, [
            'reservaVagasCotas' => [
                ['valorDestinado' => 0],
                ['vagas' => 5, 'valorDestinado' => 0],
                ['vagas' => 2, 'valorDestinado' => 0],
                ['vagas' => 3, 'valorDestinado' => 0],
            ],
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('reservaVagasCotas', $result);
    }

    function testValidateQuotasSomaDiferenteDeTotalRetornaErro()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->createOpportunity($user);

        // Total 100; soma das cotas = 99 (ampla = 59, deveria ser 60)
        $result = InMincQuotasService::validateQuotasReservation($opp, [
            'vacancies' => 100,
            'reservaVagasCotas' => $this->baseQuotas(25, 10, 5, 59),
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('reservaVagasCotas', $result);
        $this->assertStringContainsString('soma', $result['reservaVagasCotas'][0]);
    }

    function testValidateQuotasVagasNegrasAbaixoDoMinimoRetornaErro()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->createOpportunity($user);

        // Total 100; negras=24 < mínimo de 25%; soma=100 (para não cair no erro de soma)
        $result = InMincQuotasService::validateQuotasReservation($opp, [
            'vacancies' => 100,
            'reservaVagasCotas' => $this->baseQuotas(24, 10, 5, 61),
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('reservaVagasCotas', $result);
        $this->assertStringContainsString('25%', $result['reservaVagasCotas'][0]);
    }

    function testValidateQuotasSucessoRetornaFalse()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->createOpportunity($user);

        // Total 100: negras=25, indígenas=10, PCD=5, ampla=60 — soma=100, todos >= mínimo
        $result = InMincQuotasService::validateQuotasReservation($opp, [
            'vacancies' => 100,
            'reservaVagasCotas' => $this->baseQuotas(25, 10, 5, 60),
        ]);

        $this->assertFalse($result);
    }

    function testValidateQuotasParagrafo4PcdUsaDezPorCento()
    {
        $user = $this->userDirector->createUser();
        $opp = $this->createOpportunity($user);

        // § 4º: todos os ranges têm limit=1 → isParagraph4Exception=true
        // PCD mínimo = 10%; PCD=9 está abaixo desse mínimo (mas acima de 5%)
        $result = InMincQuotasService::validateQuotasReservation($opp, [
            'vacancies' => 100,
            'registrationRanges' => [['limit' => 1], ['limit' => 1]],
            'reservaVagasCotas' => $this->baseQuotas(25, 10, 9, 56),
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('reservaVagasCotas', $result);
        $this->assertStringContainsString('10%', $result['reservaVagasCotas'][0]);
    }
}
