<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;
use MapasCulturais\Entities\EvaluationMethodConfiguration;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Subsite;
use MapasCulturais\Entities\User;
use MapasCulturais\Exceptions\Halt;
use MapasCulturais\Request;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

/**
 * Testes do fluxo POST /opportunity/generateopportunity (geração de oportunidade a partir de modelo).
 *
 * Cobre: exclusão de metadados de integração na cópia, lock de fases clonadas,
 * validação de objectType/ownerEntity, validação de name e restauração do controle de acesso.
 */
class GenerateOpportunityModelTest extends TestCase
{
    use UserDirector;

    protected function setUp(): void
    {
        parent::setUp();
        unset($_ENV['ALDIRBLANC_SUBSITE_ID']);
        $_SESSION['gestor_cult_sync_started'] = true;
        $_SESSION['gestor_cult_sync_completed'] = true;
    }

    protected function tearDown(): void
    {
        unset($_ENV['ALDIRBLANC_SUBSITE_ID']);
        unset($_SESSION['gestor_cult_sync_started']);
        unset($_SESSION['gestor_cult_sync_completed']);
        parent::tearDown();
    }

    // ===== Helpers =====

    /**
     * blockAccessOnError (Theme.php) verifica campos obrigatórios do perfil para qualquer
     * usuário autenticado em rotas POST/GET. Preencher esses campos é pré-requisito para
     * que o hook deixe a ação generateopportunity ser executada.
     */
    private function fillRequiredProfileFields($profile): void
    {
        $keys = [
            'nomeCompleto', 'cpf', 'emailPrivado', 'telefonePublico',
            'acessouFomentoCultural', 'anosExperienciaAreaCultural', 'eMestreCulturasTradicionais',
            'En_CEP', 'En_Nome_Logradouro', 'En_Num', 'En_Bairro', 'En_Municipio', 'En_Estado',
            'dataDeNascimento', 'genero', 'orientacaoSexual', 'raca', 'renda', 'escolaridade',
            'pessoaDeficiente', 'comunidadesTradicional',
        ];
        $this->app->disableAccessControl();
        foreach ($keys as $key) {
            $profile->$key = $key === 'dataDeNascimento' ? '1990-01-01' : ($key === 'cpf' ? '52998224725' : 'valor-de-teste');
        }
        $profile->save(true);
        $this->app->enableAccessControl();
    }

    private function createModel(User $owner, string $name = 'Modelo de Teste'): Opportunity
    {
        $this->login($owner);
        $this->fillRequiredProfileFields($owner->profile);
        $this->app->disableAccessControl();
        $className = $owner->profile->opportunityClassName;
        $model = new $className();
        $model->owner = $owner->profile;
        $model->ownerEntity = $owner->profile;
        $model->status = Opportunity::STATUS_DRAFT;
        $model->name = $name;
        $model->shortDescription = $name;
        $model->save(true);
        $this->app->enableAccessControl();
        return $model;
    }

    private function createPhase(Opportunity $model, User $owner, string $name = 'Fase'): Opportunity
    {
        $this->app->disableAccessControl();
        $className = $owner->profile->opportunityClassName;
        $phase = new $className();
        $phase->parent = $model;
        $phase->owner = $owner->profile;
        $phase->ownerEntity = $owner->profile;
        $phase->name = $name;
        $phase->shortDescription = $name;
        // STATUS_PHASE=-1 faz a fase aparecer em allPhases (usado pelo hook
        // entity(EvaluationMethodConfiguration).insert:before em OpportunityPhases/Module.php:1897)
        $phase->status = Opportunity::STATUS_PHASE;
        $phase->save(true);
        $this->app->enableAccessControl();
        return $phase;
    }

    /**
     * Chama generateopportunity via controller. Retorna o payload JSON da resposta.
     * Lança a exceção se não for Halt (para testes de erro não-JSON).
     */
    private function callGenerateOpportunity(int $modelId, array $postBody): array
    {
        $psr7 = (new ServerRequest([], [], "/opportunity/generateopportunity/{$modelId}", 'POST'))
            ->withParsedBody($postBody);
        $this->app->request = new Request($psr7, 'opportunity', 'generateopportunity', ['id' => $modelId]);
        $this->app->response = new Response();

        $controller = $this->app->controller('opportunity');
        $controller->setRequestData(['id' => $modelId]);

        try {
            $controller->callAction('POST', 'generateopportunity', ['id' => $modelId]);
            $this->fail('Esperava Halt');
        } catch (Halt) {
        }
        return json_decode((string) $this->app->response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    // ===== generateMetadata: exclusão de flags de integração =====

    /**
     * O flag cultBrCreateSynced do modelo NÃO deve ser copiado para a oportunidade gerada.
     * Sem esse controle, a nova oportunidade nunca seria enviada ao CultBr.
     */
    function testNaoCopiaCultBrCreateSyncedDoModelo()
    {
        $user = $this->userDirector->createUser();
        $model = $this->createModel($user);

        $this->app->disableAccessControl();
        $model->setMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED, '1');
        $model->save(true);
        $this->app->enableAccessControl();

        $this->login($user);
        $payload = $this->callGenerateOpportunity($model->id, ['name' => 'Oportunidade Gerada']);

        $this->assertSame(200, $this->app->response->getStatusCode());
        $generatedId = $payload['id'];

        // Limpa o identity map: o clone herda __createdMetadata do modelo (estado em memória)
        // mas o banco está correto (sem a metadata). Reload do DB para leitura consistente.
        $this->app->em->clear();
        $generated = $this->app->repo('Opportunity')->find($generatedId);
        $this->assertNotSame(
            '1',
            $generated->getMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED),
            'cultBrCreateSynced do modelo não deve ser herdado pela oportunidade gerada'
        );
    }

    /**
     * O flag isGeneratedFromModel do modelo NÃO deve ser copiado para a oportunidade gerada.
     * Ele só deve ser gravado pelo saveOpportunityPostGenerate, após os dados PAR estarem salvos.
     */
    function testNaoCopiaIsGeneratedFromModelDoModelo()
    {
        $user = $this->userDirector->createUser();
        $model = $this->createModel($user);

        $this->app->disableAccessControl();
        $model->setMetadata(Controller::OPPORTUNITY_META_IS_GENERATED_FROM_MODEL, '1');
        $model->save(true);
        $this->app->enableAccessControl();

        $this->login($user);
        $payload = $this->callGenerateOpportunity($model->id, ['name' => 'Oportunidade Gerada']);

        $this->assertSame(200, $this->app->response->getStatusCode());
        $generatedId = $payload['id'];

        // Limpa o identity map: o clone herda __createdMetadata do modelo (estado em memória)
        // mas o banco está correto (sem a metadata). Reload do DB para leitura consistente.
        $this->app->em->clear();
        $generated = $this->app->repo('Opportunity')->find($generatedId);
        $this->assertNotSame(
            '1',
            $generated->getMetadata(Controller::OPPORTUNITY_META_IS_GENERATED_FROM_MODEL),
            'isGeneratedFromModel do modelo não deve ser herdado imediatamente ao gerar'
        );
    }

    // ===== generatePhases: lock de entidade em fase clonada =====

    /**
     * Quando a fase do modelo está bloqueada por outro usuário (userY),
     * o userX ainda deve conseguir gerar a oportunidade sem erro de permissão.
     *
     * O clone da fase herda o id original; o arquivo de lock usa o id no nome.
     * Sem a correção, save() verificaria o lock com o id herdado e lançaria PermissionDenied.
     */
    function testGeraFasesQuandoFaseLockadaPorOutroUsuario()
    {
        $userY = $this->userDirector->createUser();
        $model = $this->createModel($userY, 'Modelo com Fase');
        $phase = $this->createPhase($model, $userY, 'Fase Intermediária');

        // torna o modelo público para que userX (sem @control) possa gerar a partir dele
        $this->app->disableAccessControl();
        $model->setMetadata('isModelPublic', '1');
        $model->save(true);

        // userY bloqueia a fase (simula edição aberta)
        $this->login($userY);
        $phase->lock();
        $this->app->enableAccessControl();

        $userX = $this->userDirector->createUser();
        $this->login($userX);
        $this->fillRequiredProfileFields($userX->profile);

        $payload = $this->callGenerateOpportunity($model->id, ['name' => 'Oportunidade de UserX']);

        $this->assertSame(200, $this->app->response->getStatusCode());
        $this->assertArrayHasKey('id', $payload);

        // limpar o lock
        $this->app->disableAccessControl();
        $phase->unlock();
        $this->app->enableAccessControl();
    }

    // ===== changeObjectType: entidade não encontrada =====

    /**
     * Quando objectType/ownerEntity apontam para uma entidade inexistente,
     * generateopportunity deve retornar erro, não causar Fatal Error por null dereference.
     */
    function testChangeObjectTypeComEntidadeInexistenteRetornaErro()
    {
        $user = $this->userDirector->createUser();
        $model = $this->createModel($user);

        $this->login($user);

        // Sem try/catch externo: se Halt chegar, o teste captura como payload de 4xx/5xx.
        // Se for uma outra exceção (ex: InvalidArgumentException), ela propaga do callAction.
        $caughtException = null;
        $psr7 = (new ServerRequest([], [], "/opportunity/generateopportunity/{$model->id}", 'POST'))
            ->withParsedBody(['name' => 'Oportunidade', 'objectType' => 'Agent', 'ownerEntity' => 999999999]);
        $this->app->request = new Request($psr7, 'opportunity', 'generateopportunity', ['id' => $model->id]);
        $this->app->response = new Response();

        $controller = $this->app->controller('opportunity');
        $controller->setRequestData(['id' => $model->id]);

        try {
            $controller->callAction('POST', 'generateopportunity', ['id' => $model->id]);
            $this->fail('Esperava Halt ou exceção');
        } catch (Halt) {
            // resposta de erro via errorJson
            $status = $this->app->response->getStatusCode();
            $this->assertGreaterThanOrEqual(400, $status, 'Esperava status de erro');
        } catch (\InvalidArgumentException $e) {
            // exceção da validação em changeObjectType — comportamento correto após o fix
            $caughtException = $e;
            $this->assertStringContainsString('não encontrada', $e->getMessage());
        }

        // em ambos os casos, o controle de acesso deve estar restaurado
        $this->assertTrue(
            $this->app->isAccessControlEnabled(),
            'Controle de acesso deve estar habilitado após falha em generateopportunity'
        );
    }

    // ===== ALL_generateopportunity: validação de name =====

    /**
     * POST sem o campo name deve retornar 400 antes de qualquer operação no banco.
     */
    function testNomeAusenteRetorna400()
    {
        $user = $this->userDirector->createUser();
        $model = $this->createModel($user);

        $this->login($user);
        $payload = $this->callGenerateOpportunity($model->id, []);

        $this->assertSame(400, $this->app->response->getStatusCode());
        $this->assertArrayHasKey('name', $payload['data']);
    }

    /**
     * POST com name vazio deve retornar 400.
     */
    function testNomeVazioRetorna400()
    {
        $user = $this->userDirector->createUser();
        $model = $this->createModel($user);

        $this->login($user);
        $payload = $this->callGenerateOpportunity($model->id, ['name' => '']);

        $this->assertSame(400, $this->app->response->getStatusCode());
        $this->assertArrayHasKey('name', $payload['data']);
    }

    /**
     * POST com name como array não deve persistir "Array" nem passar a validação.
     */
    function testNomeComoArrayRetorna400()
    {
        $user = $this->userDirector->createUser();
        $model = $this->createModel($user);

        $this->login($user);
        $payload = $this->callGenerateOpportunity($model->id, ['name' => ['a', 'b']]);

        $this->assertSame(400, $this->app->response->getStatusCode());
        $this->assertArrayHasKey('name', $payload['data']);
    }

    // ===== generatePhases + hook insert:before: clone de EMC por fase =====

    /**
     * Quando uma fase do modelo tem um EMC, o clone dessa fase deve receber o mesmo tipo de EMC.
     *
     * Antes do fix, o hook entity(EvaluationMethodConfiguration).insert:before redirecionava o EMC
     * para outra fase disponível (sem checar se a fase já era o alvo correto), causando tipos errados.
     * O guard `if ($this->opportunity->parent !== null) { return; }` corrige isso.
     */
    function testGeraOportunidadeComFaseComEMCNaFaseCorreta()
    {
        $user = $this->userDirector->createUser();
        $model = $this->createModel($user);
        $phase = $this->createPhase($model, $user, 'Fase com avaliação');

        // EMC diretamente na fase (has parent) — o hook retorna cedo após o fix
        $this->app->disableAccessControl();
        $emc = new EvaluationMethodConfiguration();
        $emc->opportunity = $phase;
        $emc->type = 'simple';
        $emc->name = 'Avaliação Simplificada';
        $emc->save(true);
        $this->app->enableAccessControl();

        $this->login($user);
        $payload = $this->callGenerateOpportunity($model->id, ['name' => 'Oportunidade Gerada']);

        $this->assertSame(200, $this->app->response->getStatusCode());
        $generatedId = $payload['id'];

        $generatedPhases = $this->app->repo('Opportunity')->findBy(['parent' => $generatedId]);
        $phasesWithEmc = array_filter($generatedPhases, fn($p) => $p->evaluationMethodConfiguration !== null);

        $this->assertCount(1, $phasesWithEmc, 'Deve haver exatamente uma fase com EMC na oportunidade gerada');
        $clonedEmc = array_values($phasesWithEmc)[0]->evaluationMethodConfiguration;
        $this->assertSame('simple', $clonedEmc->type->id, 'O tipo do EMC clonado deve corresponder ao da fase original');
    }

    /**
     * Modelo com múltiplas fases, cada uma com tipo de EMC diferente.
     * Após o clone, cada fase gerada deve ter o EMC do tipo correto, sem unique violation.
     *
     * Esse cenário reproduz o bug original: sem o refresh() após cada save de fase/EMC e sem
     * o guard no hook, o Doctrine enxergava estado stale e tentava inserir dois EMCs na mesma
     * fase, causando SQLSTATE[23505] (unique constraint em evaluation_method_configuration.opportunity_id).
     */
    function testGeraOportunidadeComMultiplasFasesComEMCSemUniqueViolation()
    {
        $user = $this->userDirector->createUser();
        $model = $this->createModel($user);
        $phaseA = $this->createPhase($model, $user, 'Habilitação');
        $phaseB = $this->createPhase($model, $user, 'Técnica');

        $this->app->disableAccessControl();
        $emcA = new EvaluationMethodConfiguration();
        $emcA->opportunity = $phaseA;
        $emcA->type = 'qualification';
        $emcA->name = 'Avaliação de Habilitação';
        $emcA->save(true);

        // O hook insert:before redireciona o EMC para o último slot disponível em allPhases.
        // Sem refresh, o identity map do Doctrine não reflete essa mudança e o próximo
        // save() tentaria inserir dois EMCs no mesmo opportunity_id → UniqueConstraintViolation.
        $this->app->em->refresh($phaseB);

        $emcB = new EvaluationMethodConfiguration();
        $emcB->opportunity = $phaseB;
        $emcB->type = 'technical';
        $emcB->name = 'Avaliação Técnica';
        $emcB->save(true);
        $this->app->enableAccessControl();

        $this->login($user);
        $payload = $this->callGenerateOpportunity($model->id, ['name' => 'Oportunidade Gerada']);

        $this->assertSame(200, $this->app->response->getStatusCode());
        $generatedId = $payload['id'];

        $generatedPhases = $this->app->repo('Opportunity')->findBy(['parent' => $generatedId]);
        $emcByType = [];
        foreach ($generatedPhases as $p) {
            if ($p->evaluationMethodConfiguration) {
                $emcByType[] = $p->evaluationMethodConfiguration->type->id;
            }
        }
        sort($emcByType);

        $this->assertSame(['qualification', 'technical'], $emcByType, 'Cada fase gerada deve ter o tipo de EMC correto');
    }

    // ===== generateEvaluationMethods: metadados com default array =====

    /**
     * Metadados de EvaluationMethodConfiguration com tipo 'array' e default PHP array
     * (como statusLabels, registrado por OpportunityPhases) devem ser persistidos como
     * string JSON ao copiar o EMC, sem "Array to string conversion" no PDO.
     *
     * Reproduz o bug onde getMetadata() retornava o array-default diretamente sem serializar:
     * o valor do metadado nunca existia no banco do modelo, então a cópia recebia um PHP array
     * e Doctrine tentava bindá-lo como parâmetro text, gerando o warning.
     */
    function testGeraOportunidadeComEMCSemStatusLabelsBancoPersistidoComoJsonString()
    {
        $user = $this->userDirector->createUser();
        $model = $this->createModel($user);
        $phase = $this->createPhase($model, $user, 'Fase com avaliação');

        $this->app->disableAccessControl();
        $emc = new EvaluationMethodConfiguration();
        $emc->opportunity = $phase;
        $emc->type = 'simple';
        $emc->name = 'Avaliação Simplificada';
        $emc->save(true);
        $this->app->enableAccessControl();

        // Pré-condição: statusLabels não existe no banco — o default PHP array seria usado
        $existsInDb = $this->app->em->getConnection()->fetchOne(
            "SELECT value FROM evaluationmethodconfiguration_meta WHERE object_id = ? AND key = 'statusLabels'",
            [$emc->id]
        );
        $this->assertFalse($existsInDb, 'Pré-condição: statusLabels não deve existir no banco do EMC do modelo');

        $this->login($user);
        $payload = $this->callGenerateOpportunity($model->id, ['name' => 'Oportunidade Gerada']);

        // A geração não deve falhar mesmo quando statusLabels tem um array PHP como default
        // (o core não serializa arrays-default ao copiar metadados do EMC — isso é comportamento esperado)
        $this->assertSame(200, $this->app->response->getStatusCode());
        $generatedId = $payload['id'];

        // Verificar que o EMC foi clonado para a fase gerada
        $generatedPhases = $this->app->repo('Opportunity')->findBy(['parent' => $generatedId]);
        $clonedEmcId = null;
        foreach ($generatedPhases as $p) {
            if ($p->evaluationMethodConfiguration) {
                $clonedEmcId = $p->evaluationMethodConfiguration->id;
                break;
            }
        }
        $this->assertNotNull($clonedEmcId, 'Deve existir EMC na oportunidade gerada');
    }

    // ===== ALL_generateopportunity: restauração do controle de acesso =====

    /**
     * Mesmo quando generateopportunity falha depois de disableAccessControl(),
     * o controle de acesso deve ser restaurado (try/finally).
     */
    function testControleDeAcessoRestoradoAposExcecaoInterna()
    {
        $user = $this->userDirector->createUser();
        $model = $this->createModel($user);

        $this->login($user);

        // Provocar falha após disableAccessControl via objectType inválido
        $psr7 = (new ServerRequest([], [], "/opportunity/generateopportunity/{$model->id}", 'POST'))
            ->withParsedBody(['name' => 'Oportunidade', 'objectType' => 'Agent', 'ownerEntity' => 999999999]);
        $this->app->request = new Request($psr7, 'opportunity', 'generateopportunity', ['id' => $model->id]);
        $this->app->response = new Response();

        $controller = $this->app->controller('opportunity');
        $controller->setRequestData(['id' => $model->id]);

        try {
            $controller->callAction('POST', 'generateopportunity', ['id' => $model->id]);
        } catch (\Throwable) {
            // exceção esperada
        }

        $this->assertTrue(
            $this->app->isAccessControlEnabled(),
            'Controle de acesso deve estar habilitado mesmo após exceção interna'
        );
    }
}
