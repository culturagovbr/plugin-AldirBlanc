<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Services\OpportunityService;
use MapasCulturais\ApiQuery;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Subsite;
use MapasCulturais\Entities\User;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

/**
 * OpportunityService::syncableApiQueryFilters — os filtros com que a listagem pagina já
 * restrita às publicadas que podem ser enviadas ao CultBR.
 *
 * O filtro é um superconjunto da regra: o que importa é que nenhuma publicada elegível fique de
 * fora. Ele não apara espaços (a API não tem operador com trim), então um metadado só com espaços
 * passa aqui e é barrado pela consulta de status de cada card, que usa isEligibleForSync.
 */
class OpportunityServiceSyncableFiltersTest extends TestCase
{
    use UserDirector;

    private const PAR_COMPLETO = [
        'parExercicioId' => '2024',
        'parMetaId' => '2',
        'parAcaoId' => '3',
        'parAtividadeId' => '4',
    ];

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

    private function service(): OpportunityService
    {
        return new OpportunityService();
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
        $opportunity->name = 'Oportunidade de filtro';
        $opportunity->shortDescription = 'desc';
        $opportunity->status = $overrides['status'] ?? Opportunity::STATUS_ENABLED;
        $opportunity->subsite = $subsite;

        if (isset($overrides['parent'])) {
            $opportunity->parent = $overrides['parent'];
        }

        $opportunity->save(true);

        if ($overrides['federativeEntityId'] ?? true) {
            $opportunity->setMetadata('federativeEntityId', 1);
        }

        foreach ($overrides['par'] ?? self::PAR_COMPLETO as $key => $value) {
            $opportunity->setMetadata($key, $value);
        }

        $opportunity->save(true);
        $this->app->enableAccessControl();

        return $opportunity;
    }

    /**
     * Escreve direto na entidade de metadado: setMetadata() converte string em branco em null
     * (Entity::setMetadata), então é o único jeito de reproduzir esse estado.
     */
    private function blankMetadata(Opportunity $opportunity, string $key, string $value = '   '): void
    {
        $meta = $this->app->repo('OpportunityMeta')->findOneBy(['owner' => $opportunity, 'key' => $key]);
        $this->assertNotNull($meta, "Metadado {$key} deveria existir para ser esvaziado");

        $meta->value = $value;
        $this->app->em->flush();
    }

    /** Ids que a API devolve para os filtros indicados (por padrão, os de elegibilidade). */
    private function filteredIds(array $opportunities, ?array $filters = null): array
    {
        $ids = array_map(fn(Opportunity $opportunity) => $opportunity->id, $opportunities);

        $params = ($filters ?? $this->service()->syncableApiQueryFilters()) + [
            'id' => 'IN(' . implode(',', $ids) . ')',
            '@select' => 'id',
        ];

        $this->app->disableAccessControl();
        $result = (new ApiQuery(Opportunity::class, $params))->findIds();
        $this->app->enableAccessControl();

        return array_map('intval', $result);
    }

    /** Elegíveis pela regra e publicadas — o recorte que a listagem mostra. */
    private function eligibleIds(array $opportunities): array
    {
        $service = $this->service();

        return array_values(array_map(
            fn(Opportunity $opportunity) => (int) $opportunity->id,
            array_filter(
                $opportunities,
                fn(Opportunity $o) => $service->isEligibleForSync($o) && $o->status >= Opportunity::STATUS_ENABLED
            )
        ));
    }

    /**
     * A propriedade de que a listagem depende: nenhuma publicada que a regra aprova pode sumir
     * do filtro. O contrário é tolerável — um inelegível a mais é desmarcado card a card.
     */
    function testFiltroNaoEscondeNenhumElegivel()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->subsite($owner, 'Subsite Pnab Equivalencia');
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        $elegivel = $this->opportunity($owner, $subsite);
        $rascunho = $this->opportunity($owner, $subsite, ['status' => Opportunity::STATUS_DRAFT]);
        $arquivada = $this->opportunity($owner, $subsite, ['status' => Opportunity::STATUS_ARCHIVED]);
        $semEnte = $this->opportunity($owner, $subsite, ['federativeEntityId' => false]);
        $semPar = $this->opportunity($owner, $subsite, ['par' => []]);
        $parParcial = $this->opportunity($owner, $subsite, ['par' => ['parExercicioId' => '2024']]);
        $fase = $this->opportunity($owner, $subsite, ['status' => Opportunity::STATUS_PHASE]);
        $complementar = $this->opportunity($owner, $subsite, ['parent' => $elegivel]);

        $todas = [$elegivel, $rascunho, $arquivada, $semEnte, $semPar, $parParcial, $fase, $complementar];

        $filtrados = $this->filteredIds($todas);
        $elegiveis = $this->eligibleIds($todas);

        sort($filtrados);
        sort($elegiveis);

        $this->assertSame($elegiveis, $filtrados);
    }

    /**
     * Diferença conhecida: a API não apara espaços, então o metadado só com espaços passa pelo
     * filtro. Quem barra é a consulta de status do card — este teste trava as duas metades.
     */
    function testMetadadoSoComEspacosPassaNoFiltroEhBarradoPelaRegra()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->subsite($owner, 'Subsite Pnab Espacos');
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        $opportunity = $this->opportunity($owner, $subsite);
        $this->blankMetadata($opportunity, 'parAcaoId');

        $this->assertContains((int) $opportunity->id, $this->filteredIds([$opportunity]));
        $this->assertFalse($this->service()->isEligibleForSync($opportunity));
    }

    /** Metadado gravado como string vazia é excluído pelo filtro, sem depender da regra. */
    function testMetadadoVazioNaoPassaNoFiltro()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->subsite($owner, 'Subsite Pnab Vazio');
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        $opportunity = $this->opportunity($owner, $subsite);
        $this->blankMetadata($opportunity, 'parAcaoId', '');

        $this->assertNotContains((int) $opportunity->id, $this->filteredIds([$opportunity]));
    }

    function testFiltroDevolveSomenteAsPublicadas()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->subsite($owner, 'Subsite Pnab Publicadas');
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        $publicada = $this->opportunity($owner, $subsite);
        $rascunho = $this->opportunity($owner, $subsite, ['status' => Opportunity::STATUS_DRAFT]);
        $arquivada = $this->opportunity($owner, $subsite, ['status' => Opportunity::STATUS_ARCHIVED]);

        $filtrados = $this->filteredIds([$publicada, $rascunho, $arquivada]);

        $this->assertContains((int) $publicada->id, $filtrados);
        $this->assertNotContains((int) $rascunho->id, $filtrados);
        $this->assertNotContains((int) $arquivada->id, $filtrados);
    }

    function testFiltroExcluiFaseComplementarESemDadosDoPar()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->subsite($owner, 'Subsite Pnab Exclusoes');
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        $elegivel = $this->opportunity($owner, $subsite);
        $fase = $this->opportunity($owner, $subsite, ['status' => Opportunity::STATUS_PHASE]);
        $complementar = $this->opportunity($owner, $subsite, ['parent' => $elegivel]);
        $semPar = $this->opportunity($owner, $subsite, ['par' => []]);
        $semEnte = $this->opportunity($owner, $subsite, ['federativeEntityId' => false]);

        $filtrados = $this->filteredIds([$elegivel, $fase, $complementar, $semPar, $semEnte]);

        $this->assertContains((int) $elegivel->id, $filtrados);
        $this->assertNotContains((int) $fase->id, $filtrados);
        $this->assertNotContains((int) $complementar->id, $filtrados);
        $this->assertNotContains((int) $semPar->id, $filtrados);
        $this->assertNotContains((int) $semEnte->id, $filtrados);
    }

    function testRecorteDaListagemMostraElegiveisEInelegiveis()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->subsite($owner, 'Subsite Pnab Listagem');
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        $elegivel = $this->opportunity($owner, $subsite);
        $semPar = $this->opportunity($owner, $subsite, ['par' => []]);
        $semEnte = $this->opportunity($owner, $subsite, ['federativeEntityId' => false]);

        $filtrados = $this->filteredIds(
            [$elegivel, $semPar, $semEnte],
            $this->service()->listingApiQueryFilters()
        );

        $this->assertContains((int) $elegivel->id, $filtrados);
        $this->assertContains((int) $semPar->id, $filtrados, 'O modo "todas" mostra a inelegível, com o motivo no card');
        $this->assertContains((int) $semEnte->id, $filtrados);
    }

    function testRecorteDaListagemSegueExcluindoFaseComplementarERascunho()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->subsite($owner, 'Subsite Pnab Listagem Exclusoes');
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        $publicada = $this->opportunity($owner, $subsite);
        $fase = $this->opportunity($owner, $subsite, ['status' => Opportunity::STATUS_PHASE]);
        $complementar = $this->opportunity($owner, $subsite, ['parent' => $publicada]);
        $rascunho = $this->opportunity($owner, $subsite, ['status' => Opportunity::STATUS_DRAFT]);

        $filtrados = $this->filteredIds(
            [$publicada, $fase, $complementar, $rascunho],
            $this->service()->listingApiQueryFilters()
        );

        $this->assertContains((int) $publicada->id, $filtrados);
        $this->assertNotContains((int) $fase->id, $filtrados);
        $this->assertNotContains((int) $complementar->id, $filtrados);
        $this->assertNotContains((int) $rascunho->id, $filtrados);
    }

    function testSincronizaveisSaoORecorteDaListagemMaisAElegibilidade()
    {
        $listagem = $this->service()->listingApiQueryFilters();
        $sincronizaveis = $this->service()->syncableApiQueryFilters();

        foreach ($listagem as $chave => $valor) {
            $this->assertSame($valor, $sincronizaveis[$chave] ?? null, "O filtro {$chave} precisa valer nos dois modos");
        }

        $this->assertSame(
            ['federativeEntityId', 'parExercicioId', 'parMetaId', 'parAcaoId', 'parAtividadeId'],
            array_values(array_diff(array_keys($sincronizaveis), array_keys($listagem))),
            'A diferença entre os dois modos é exatamente a elegibilidade'
        );
    }

    /**
     * O tema normaliza o status em API.find(opportunity).params e descarta negação — um filtro
     * como !EQ(-1) chegaria ao banco como EQ(-1), listando exatamente o que deveria excluir.
     * Este teste roda os filtros pelo hook, como a requisição faz.
     */
    function testFiltrosSobrevivemAoHookDoTema()
    {
        $params = $this->service()->syncableApiQueryFilters();
        $originais = $params;

        $this->app->applyHookBoundTo(
            $this->app->controller('opportunity'),
            'API.find(opportunity).params',
            [&$params]
        );

        $this->assertSame($originais, $params, 'O hook do tema não pode alterar os filtros');
    }

    function testFiltroCobreTodosOsMetadadosDaRegra()
    {
        $filtros = $this->service()->syncableApiQueryFilters();

        foreach (['federativeEntityId', 'parExercicioId', 'parMetaId', 'parAcaoId', 'parAtividadeId'] as $key) {
            $this->assertArrayHasKey($key, $filtros, "Filtro sem {$key}");
        }

        $this->assertArrayHasKey('parent', $filtros);
        $this->assertArrayHasKey('status', $filtros);
    }
}
