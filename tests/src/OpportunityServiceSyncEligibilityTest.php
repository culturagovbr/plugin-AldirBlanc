<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Enum\SyncIneligibilityReason;
use AldirBlanc\Services\OpportunityService;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Subsite;
use MapasCulturais\Entities\User;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

/**
 * Regra que decide se uma oportunidade é enviada ao CultBR
 * (OpportunityService::isEligibleForSync e syncIneligibilityReason).
 *
 * É a regra que o Pnab\Theme aplica no save; o efeito dela sobre o enfileiramento
 * está coberto em ThemeGenerateOpportunityHooksTest.
 */
class OpportunityServiceSyncEligibilityTest extends TestCase
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

    /**
     * Oportunidade que passa em todos os guards, salvo o que os overrides mudarem:
     * `subsite`, `status`, `parent`, `federativeEntityId` e `par` (mapa de metadados do PAR).
     */
    private function opportunity(User $owner, array $overrides = []): Opportunity
    {
        $this->login($owner);
        $this->app->disableAccessControl();

        $opportunityClassName = $owner->profile->opportunityClassName;
        $opportunity = new $opportunityClassName();
        $opportunity->owner = $owner->profile;
        $opportunity->ownerEntity = $owner->profile;
        $opportunity->name = 'Oportunidade de elegibilidade';
        $opportunity->shortDescription = 'desc';
        $opportunity->status = $overrides['status'] ?? Opportunity::STATUS_ENABLED;

        if (array_key_exists('subsite', $overrides)) {
            $opportunity->subsite = $overrides['subsite'];
        }

        if (isset($overrides['parent'])) {
            $opportunity->parent = $overrides['parent'];
        }

        $opportunity->save(true);

        if (array_key_exists('federativeEntityId', $overrides)) {
            $opportunity->setMetadata('federativeEntityId', $overrides['federativeEntityId']);
        } else {
            $opportunity->setMetadata('federativeEntityId', 1);
        }

        $par = $overrides['par'] ?? self::PAR_COMPLETO;
        foreach ($par as $key => $value) {
            $opportunity->setMetadata($key, $value);
        }

        $opportunity->save(true);
        $this->app->enableAccessControl();

        return $opportunity;
    }

    /**
     * Grava um valor em branco no metadado. setMetadata() converte string em branco em null
     * (Entity::setMetadata), então o único jeito de reproduzir o valor em branco que a regra
     * apara é escrevendo na própria entidade de metadado.
     */
    private function blankMetadata(Opportunity $opportunity, string $key): void
    {
        $meta = $this->app->repo('OpportunityMeta')->findOneBy([
            'owner' => $opportunity,
            'key' => $key,
        ]);
        $this->assertNotNull($meta, "Metadado {$key} deveria existir para ser esvaziado");

        $meta->value = '   ';
        $this->app->em->flush();
    }

    /** Subsite da oportunidade é o subsite da integração. */
    private function integrationSubsite(User $owner, string $name = 'Subsite Pnab'): Subsite
    {
        $subsite = $this->subsite($owner, $name);
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;
        return $subsite;
    }

    function testOportunidadeCompletaEhElegivel()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner);
        $opportunity = $this->opportunity($owner, ['subsite' => $subsite]);

        $this->assertTrue($this->service()->isEligibleForSync($opportunity));
        $this->assertNull($this->service()->syncIneligibilityReason($opportunity));
    }

    function testRascunhoEhElegivel()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Pnab Rascunho');
        $opportunity = $this->opportunity($owner, [
            'subsite' => $subsite,
            'status' => Opportunity::STATUS_DRAFT,
        ]);

        $this->assertTrue(
            $this->service()->isEligibleForSync($opportunity),
            'A regra não exige publicação: o envio ocorre em qualquer save'
        );
    }

    function testSemEnteFederadoNaoEhElegivel()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Pnab Sem Ente');
        $opportunity = $this->opportunity($owner, [
            'subsite' => $subsite,
            'federativeEntityId' => null,
        ]);

        $this->assertFalse($this->service()->isEligibleForSync($opportunity));
        $this->assertSame(
            SyncIneligibilityReason::NO_FEDERATIVE_ENTITY,
            $this->service()->syncIneligibilityReason($opportunity)
        );
    }

    function testEnteFederadoEmBrancoNaoEhElegivel()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Pnab Ente Branco');
        $opportunity = $this->opportunity($owner, ['subsite' => $subsite]);
        $this->blankMetadata($opportunity, 'federativeEntityId');

        $this->assertSame(
            SyncIneligibilityReason::NO_FEDERATIVE_ENTITY,
            $this->service()->syncIneligibilityReason($opportunity)
        );
    }

    function testSemSubsiteNaoEhElegivel()
    {
        $owner = $this->userDirector->createUser();
        $this->integrationSubsite($owner, 'Subsite Pnab Sem Vinculo');
        $opportunity = $this->opportunity($owner);

        $this->assertSame(
            SyncIneligibilityReason::NO_SUBSITE,
            $this->service()->syncIneligibilityReason($opportunity)
        );
    }

    function testSubsiteDaIntegracaoNaoConfiguradoNaoEhElegivel()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->subsite($owner, 'Subsite Sem Env');
        $opportunity = $this->opportunity($owner, ['subsite' => $subsite]);

        $this->assertSame(
            SyncIneligibilityReason::SUBSITE_NOT_CONFIGURED,
            $this->service()->syncIneligibilityReason($opportunity)
        );
    }

    function testOutroSubsiteNaoEhElegivel()
    {
        $owner = $this->userDirector->createUser();
        $outroSubsite = $this->subsite($owner, 'Subsite da Oportunidade');
        $this->integrationSubsite($owner, 'Subsite Pnab Outro');
        $opportunity = $this->opportunity($owner, ['subsite' => $outroSubsite]);

        $this->assertSame(
            SyncIneligibilityReason::OTHER_SUBSITE,
            $this->service()->syncIneligibilityReason($opportunity)
        );
    }

    function testFaseNaoEhElegivel()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Pnab Fase');
        $opportunity = $this->opportunity($owner, [
            'subsite' => $subsite,
            'status' => Opportunity::STATUS_PHASE,
        ]);

        $this->assertSame(
            SyncIneligibilityReason::IS_PHASE,
            $this->service()->syncIneligibilityReason($opportunity)
        );
    }

    function testOportunidadeComplementarNaoEhElegivel()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Pnab Complementar');
        $parent = $this->opportunity($owner, ['subsite' => $subsite]);
        $opportunity = $this->opportunity($owner, [
            'subsite' => $subsite,
            'parent' => $parent,
        ]);

        $this->assertSame(
            SyncIneligibilityReason::HAS_PARENT,
            $this->service()->syncIneligibilityReason($opportunity)
        );
    }

    /**
     * @dataProvider parMetadataKeys
     */
    function testParIncompletoNaoEhElegivel(string $missingKey)
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Pnab Par ' . $missingKey);

        $par = self::PAR_COMPLETO;
        unset($par[$missingKey]);

        $opportunity = $this->opportunity($owner, ['subsite' => $subsite, 'par' => $par]);

        $this->assertSame(
            SyncIneligibilityReason::INCOMPLETE_PAR,
            $this->service()->syncIneligibilityReason($opportunity),
            "Sem {$missingKey} a oportunidade não pode ser enviada"
        );
    }

    /**
     * @dataProvider parMetadataKeys
     */
    function testParEmBrancoNaoEhElegivel(string $blankKey)
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Pnab Branco ' . $blankKey);

        $opportunity = $this->opportunity($owner, ['subsite' => $subsite]);
        $this->blankMetadata($opportunity, $blankKey);

        $this->assertSame(
            SyncIneligibilityReason::INCOMPLETE_PAR,
            $this->service()->syncIneligibilityReason($opportunity),
            "{$blankKey} em branco não conta como preenchido"
        );
    }

    function testTodoMotivoTemLabel()
    {
        foreach (SyncIneligibilityReason::cases() as $reason) {
            $this->assertNotSame('', trim($reason->label()), "Motivo {$reason->value} sem label");
        }
    }

    public static function parMetadataKeys(): array
    {
        return array_map(fn($key) => [$key], array_combine(
            array_keys(self::PAR_COMPLETO),
            array_keys(self::PAR_COMPLETO)
        ));
    }
}
