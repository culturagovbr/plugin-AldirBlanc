<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Entities\CultBrRequestLog;
use AldirBlanc\Enum\Role;
use AldirBlanc\Services\CultBrRequestLogService;
use Laminas\Diactoros\Response;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Subsite;
use MapasCulturais\Entities\User;
use MapasCulturais\Exceptions\Halt;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\TestableController;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

/**
 * GET /aldirblanc/opportunitiesSyncStatus — elegibilidade e último envio dos cards da tela
 * de sincronização, restrita a saasSuperAdmin.
 */
class ControllerOpportunitiesSyncStatusTest extends TestCase
{
    use UserDirector;
    use RequestFactory;

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

    /** O 401 do visitante é respondido pelo core, e não escreve corpo nesta resposta. */
    private function callGet(mixed $opportunityIds): void
    {
        $controller = new TestableController();
        $controller->data = $opportunityIds === null ? [] : ['opportunityIds' => $opportunityIds];

        $this->app->response = new Response();

        try {
            $controller->callOpportunitiesSyncStatus();
            $this->fail('Esperava que o controller encerrasse a resposta com Halt');
        } catch (Halt) {
        }
    }

    private function get(mixed $opportunityIds): array
    {
        $this->callGet($opportunityIds);

        return json_decode((string) $this->app->response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function responseStatus(): int
    {
        return $this->app->response->getStatusCode();
    }

    private function integrationSubsite(User $owner): Subsite
    {
        $this->login($owner);
        $this->app->disableAccessControl();
        $subsite = new Subsite();
        $subsite->name = 'Subsite Status';
        $subsite->url = 'status-' . uniqid();
        $subsite->save(true);
        $this->app->enableAccessControl();

        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        return $subsite;
    }

    private function opportunity(User $owner, Subsite $subsite, bool $withPar = true): Opportunity
    {
        $this->login($owner);
        $this->app->disableAccessControl();

        $className = $owner->profile->opportunityClassName;
        $opportunity = new $className();
        $opportunity->owner = $owner->profile;
        $opportunity->ownerEntity = $owner->profile;
        $opportunity->name = 'Oportunidade de status';
        $opportunity->shortDescription = 'desc';
        $opportunity->status = Opportunity::STATUS_ENABLED;
        $opportunity->subsite = $subsite;
        $opportunity->save(true);

        $opportunity->setMetadata('federativeEntityId', 1);

        if ($withPar) {
            foreach (['parExercicioId' => '2024', 'parMetaId' => '2', 'parAcaoId' => '3', 'parAtividadeId' => '4'] as $key => $value) {
                $opportunity->setMetadata($key, $value);
            }
        }

        $opportunity->save(true);
        $this->app->enableAccessControl();

        return $opportunity;
    }

    private function log(int $opportunityId, string $result, string $createdAt): CultBrRequestLog
    {
        $service = new CultBrRequestLogService();
        $log = $service->startOrResume($opportunityId, 'update');
        $log->createTimestamp = new \DateTime($createdAt);
        $service->finish($log, $result);

        return $log;
    }

    private function admin(): User
    {
        $user = $this->userDirector->createUser([Role::SAAS_SUPER_ADMIN]);
        $this->login($user);

        return $user;
    }

    function testVisitanteNaoConsultaStatus()
    {
        $this->app->auth->authenticatedUser = null;
        $this->app->request = $this->requestFactory->mapasPOST('aldirblanc', 'opportunitiesSyncStatus');

        $this->callGet('1,2');

        $this->assertSame(401, $this->responseStatus());
    }

    function testUsuarioSemSaasSuperAdminRecebe403()
    {
        $this->login($this->userDirector->createUser([Role::ADMIN]));

        $this->callGet('1,2');

        $this->assertSame(403, $this->responseStatus());
    }

    function testSelecaoInvalidaRecebe400()
    {
        $this->admin();

        $this->callGet('1,abc');

        $this->assertSame(400, $this->responseStatus());
    }

    function testSelecaoAcimaDoTetoRecebe400()
    {
        $this->admin();

        $this->callGet(implode(',', range(1, 501)));

        $this->assertSame(400, $this->responseStatus(), 'A consulta carrega cada oportunidade hidratada, e por isso tem o mesmo teto do disparo');
    }

    function testSelecaoNoTetoEhAceita()
    {
        $this->admin();

        $this->callGet(implode(',', range(1, 500)));

        $this->assertSame(200, $this->responseStatus());
    }

    function testOportunidadeElegivelVemComoSincronizavelSemMotivo()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner);
        $elegivel = $this->opportunity($owner, $subsite);

        $this->admin();
        $status = $this->get((string) $elegivel->id);

        $this->assertTrue($status[$elegivel->id]['syncable']);
        $this->assertNull($status[$elegivel->id]['reason']);
    }

    function testOportunidadeInelegivelVemComOMotivo()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner);
        $semPar = $this->opportunity($owner, $subsite, withPar: false);

        $this->admin();
        $status = $this->get((string) $semPar->id);

        $this->assertFalse($status[$semPar->id]['syncable']);
        $this->assertSame('Dados do PAR incompletos', $status[$semPar->id]['reason']);
    }

    function testOportunidadeNuncaEnviadaVemSemUltimoEnvio()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner);
        $opportunity = $this->opportunity($owner, $subsite);

        $this->admin();
        $status = $this->get((string) $opportunity->id);

        $this->assertNull($status[$opportunity->id]['lastSync']);
    }

    function testUltimoEnvioEhOMaisRecenteDaOportunidade()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner);
        $opportunity = $this->opportunity($owner, $subsite);

        $this->log((int) $opportunity->id, CultBrRequestLog::RESULT_ERROR, '2026-01-01 10:00:00');
        $this->log((int) $opportunity->id, CultBrRequestLog::RESULT_SUCCESS, '2026-02-01 10:00:00');

        $this->admin();
        $status = $this->get((string) $opportunity->id);

        $this->assertSame(CultBrRequestLog::RESULT_SUCCESS, $status[$opportunity->id]['lastSync']['result']);
        $this->assertStringStartsWith('2026-02-01', $status[$opportunity->id]['lastSync']['date']);
    }

    function testEnviosNoMesmoInstanteDesempatamPeloMaisRecente()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner);
        $opportunity = $this->opportunity($owner, $subsite);

        $this->log((int) $opportunity->id, CultBrRequestLog::RESULT_ERROR, '2026-03-01 10:00:00');
        $this->log((int) $opportunity->id, CultBrRequestLog::RESULT_SUCCESS, '2026-03-01 10:00:00');

        $this->admin();
        $status = $this->get((string) $opportunity->id);

        $this->assertSame(
            CultBrRequestLog::RESULT_SUCCESS,
            $status[$opportunity->id]['lastSync']['result'],
            'Com o mesmo horário, o último envio é o gravado por último'
        );
    }

    function testCadaOportunidadeRecebeOSeuUltimoEnvio()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner);
        $uma = $this->opportunity($owner, $subsite);
        $outra = $this->opportunity($owner, $subsite);

        $this->log((int) $uma->id, CultBrRequestLog::RESULT_SUCCESS, '2026-01-01 10:00:00');
        $this->log((int) $outra->id, CultBrRequestLog::RESULT_ERROR, '2026-01-02 10:00:00');

        $this->admin();
        $status = $this->get("{$uma->id},{$outra->id}");

        $this->assertSame(CultBrRequestLog::RESULT_SUCCESS, $status[$uma->id]['lastSync']['result']);
        $this->assertSame(CultBrRequestLog::RESULT_ERROR, $status[$outra->id]['lastSync']['result']);
    }

    function testIdInexistenteNaoApareceNoResultado()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner);
        $existente = $this->opportunity($owner, $subsite);
        $inexistente = ((int) $existente->id) + 999999;

        $this->admin();
        $status = $this->get("{$existente->id},{$inexistente}");

        $this->assertArrayHasKey($existente->id, $status);
        $this->assertArrayNotHasKey($inexistente, $status);
    }

    function testSelecaoVaziaDevolveResultadoVazio()
    {
        $this->admin();

        $this->assertSame([], $this->get(''));
    }
}
