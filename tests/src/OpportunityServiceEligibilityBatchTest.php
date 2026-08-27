<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Services\OpportunityService;
use Doctrine\DBAL\Logging\DebugStack;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Subsite;
use MapasCulturais\Entities\User;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\TestableOpportunityService;
use Tests\Traits\UserDirector;

/**
 * OpportunityService::findOpportunitiesForEligibilityCheck — carga em lote do que a regra de
 * elegibilidade lê, para o job em lote e a consulta de status dos cards não fazerem
 * uma consulta de metadados por oportunidade.
 */
class OpportunityServiceEligibilityBatchTest extends TestCase
{
    use UserDirector;

    protected function setUp(): void
    {
        parent::setUp();
        unset($_ENV['ALDIRBLANC_SUBSITE_ID']);
    }

    protected function tearDown(): void
    {
        unset($_ENV['ALDIRBLANC_SUBSITE_ID']);
        parent::tearDown();
    }

    private function subsite(User $owner, string $name): Subsite
    {
        $this->login($owner);
        $this->app->disableAccessControl();
        $subsite = new Subsite();
        $subsite->name = $name;
        $subsite->url = strtolower(str_replace(' ', '-', $name)) . '-' . uniqid();
        $subsite->save(true);
        $this->app->enableAccessControl();
        return $subsite;
    }

    private function opportunity(User $owner, Subsite $subsite, array $overrides = []): Opportunity
    {
        $this->login($owner);
        $this->app->disableAccessControl();

        $opportunityClassName = $owner->profile->opportunityClassName;
        $opportunity = new $opportunityClassName();
        $opportunity->owner = $owner->profile;
        $opportunity->ownerEntity = $owner->profile;
        $opportunity->name = 'Oportunidade de lote';
        $opportunity->shortDescription = 'desc';
        $opportunity->status = Opportunity::STATUS_ENABLED;
        $opportunity->subsite = $subsite;

        if (isset($overrides['parent'])) {
            $opportunity->parent = $overrides['parent'];
        }

        $opportunity->save(true);

        $opportunity->setMetadata('federativeEntityId', 1);
        foreach (['parExercicioId' => '2024', 'parMetaId' => '2', 'parAcaoId' => '3', 'parAtividadeId' => '4'] as $key => $value) {
            $opportunity->setMetadata($key, $value);
        }
        $opportunity->save(true);

        $this->app->enableAccessControl();

        return $opportunity;
    }

    /**
     * Executa a carga contando as consultas — a única forma de verificar os fetch-joins, já que
     * as associações envolvidas são EAGER e ficariam carregadas de um jeito ou de outro.
     *
     * @return array{0: array<int, Opportunity>, 1: int}
     */
    private function loadCountingQueries(array $ids, ?OpportunityService $service = null): array
    {
        $connection = $this->app->em->getConnection();
        $configuration = $connection->getConfiguration();
        $previous = $configuration->getSQLLogger();

        $stack = new DebugStack();
        $configuration->setSQLLogger($stack);

        try {
            $result = ($service ?? new OpportunityService())->findOpportunitiesForEligibilityCheck($ids);
        } finally {
            $configuration->setSQLLogger($previous);
        }

        return [$result, count($stack->queries)];
    }

    function testDevolveOportunidadesIndexadasPorId()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->subsite($owner, 'Subsite Lote');
        $uma = $this->opportunity($owner, $subsite);
        $outra = $this->opportunity($owner, $subsite);

        $resultado = (new OpportunityService())->findOpportunitiesForEligibilityCheck([$uma->id, $outra->id]);

        $chaves = array_keys($resultado);
        sort($chaves);
        $esperadas = [(int) $uma->id, (int) $outra->id];
        sort($esperadas);

        $this->assertSame($esperadas, $chaves, 'As chaves precisam ser os ids das oportunidades');
        $this->assertInstanceOf(Opportunity::class, $resultado[(int) $uma->id]);
    }

    function testTrazMetadadosSubsiteEParentNaMesmaConsulta()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->subsite($owner, 'Subsite Lote Hidratado');
        $pai = $this->opportunity($owner, $subsite);
        $filha = $this->opportunity($owner, $subsite, ['parent' => $pai]);

        $filhaId = (int) $filha->id;
        $subsiteId = (int) $subsite->id;
        $paiId = (int) $pai->id;

        $this->app->em->clear();

        $carregada = (new OpportunityService())->findOpportunitiesForEligibilityCheck([$filhaId])[$filhaId];

        $this->assertSame($subsiteId, (int) $carregada->subsite->id);
        $this->assertSame($paiId, (int) $carregada->parent->id);
        $this->assertSame('2024', $carregada->getMetadata('parExercicioId'));
    }

    /**
     * O motivo de o método existir: carregar cinco oportunidades não pode custar mais consultas
     * que carregar uma. `__metadata` é EAGER, então sem os fetch-joins o Doctrine buscaria as
     * associações de cada oportunidade separadamente.
     */
    function testNaoFazUmaConsultaPorOportunidade()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->subsite($owner, 'Subsite Lote Consultas');

        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = (int) $this->opportunity($owner, $subsite)->id;
        }

        $this->app->em->clear();
        [, $consultasParaUma] = $this->loadCountingQueries([$ids[0]]);

        $this->app->em->clear();
        [$resultado, $consultasParaCinco] = $this->loadCountingQueries($ids);

        $this->assertCount(5, $resultado);
        $this->assertSame(
            $consultasParaUma,
            $consultasParaCinco,
            'O número de consultas não pode crescer com a quantidade de oportunidades'
        );
    }

    /** O que volta precisa servir à regra sem disparar carga adicional de metadado. */
    function testResultadoServeParaAChecagemDeElegibilidade()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->subsite($owner, 'Subsite Lote Regra');
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        $opportunity = $this->opportunity($owner, $subsite);

        $service = new OpportunityService();
        $resultado = $service->findOpportunitiesForEligibilityCheck([$opportunity->id]);

        $this->assertTrue($service->isEligibleForSync($resultado[(int) $opportunity->id]));
    }

    function testIgnoraIdInexistente()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->subsite($owner, 'Subsite Lote Inexistente');
        $existente = $this->opportunity($owner, $subsite);

        $resultado = (new OpportunityService())->findOpportunitiesForEligibilityCheck([$existente->id, 999999999]);

        $this->assertSame([(int) $existente->id], array_keys($resultado));
    }

    function testListaVaziaDevolveVazio()
    {
        $this->assertSame([], (new OpportunityService())->findOpportunitiesForEligibilityCheck([]));
    }

    function testIdsInvalidosSaoDescartadosSemConsultar()
    {
        [$resultado, $consultas] = $this->loadCountingQueries([0, -1, 'abc', null]);

        $this->assertSame([], $resultado);
        $this->assertSame(0, $consultas, 'Id inválido não deve chegar ao banco');
    }

    /** Com lote de 2, três repetições do mesmo id viram um bloco só — não dois. */
    function testIdRepetidoNaoDuplicaNemGeraBlocoExtra()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->subsite($owner, 'Subsite Lote Repetido');
        $id = (int) $this->opportunity($owner, $subsite)->id;

        $this->app->em->clear();
        [$umSo, $consultasUmSo] = $this->loadCountingQueries([$id], new TestableOpportunityService());

        $this->app->em->clear();
        [$repetido, $consultasRepetido] = $this->loadCountingQueries(
            [$id, $id, $id],
            new TestableOpportunityService()
        );

        $this->assertCount(1, $umSo);
        $this->assertCount(1, $repetido);
        $this->assertSame($consultasUmSo, $consultasRepetido, 'Id repetido não pode virar bloco a mais');
    }

    /**
     * Com lote de 2 (ver o double), cinco oportunidades são percorridas em três blocos: duas
     * consultas a mais que em bloco único, e nenhuma oportunidade perdida pelo caminho.
     */
    function testLoteMaiorQueOBlocoVoltaInteiro()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->subsite($owner, 'Subsite Lote Blocos');

        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = (int) $this->opportunity($owner, $subsite)->id;
        }

        $this->app->em->clear();
        [, $emBlocoUnico] = $this->loadCountingQueries($ids);

        $this->app->em->clear();
        [$resultado, $emTresBlocos] = $this->loadCountingQueries($ids, new TestableOpportunityService());

        sort($ids);
        $chaves = array_keys($resultado);
        sort($chaves);

        $this->assertSame($ids, $chaves, 'Nenhuma oportunidade pode se perder entre os blocos');
        $this->assertSame($emBlocoUnico + 2, $emTresBlocos, 'Três blocos são duas consultas a mais');
    }
}
