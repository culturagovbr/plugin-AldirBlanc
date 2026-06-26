<?php

namespace Tests\AldirBlanc\Legacy;

use AldirBlanc\Controller;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Subsite;
use MapasCulturais\Exceptions\Halt;
use MapasCulturais\Request;
use Tests\Abstract\TestCase;
use Tests\Traits\UserDirector;

/**
 * Testes de regressão para o fluxo de duplicação de oportunidade.
 *
 * O fluxo é implementado em EntityOpportunityDuplicator (src/core/Traits/).
 * Diferença crucial em relação ao fluxo "usar modelo" (generateopportunity):
 *
 * - duplicate: copia TODOS os metadados do original, incluindo flags de integração
 *   (cultBrCreateSynced, isGeneratedFromModel). Semanticamente é uma "cópia fiel".
 *
 * - generateopportunity: EXCLUI cultBrCreateSynced e isGeneratedFromModel via hook
 *   EntityManagerModel.generateMetadata.excludedKeys registrado no Plugin AldirBlanc.
 *
 * A cópia sempre começa em STATUS_DRAFT, então mesmo que cultBrCreateSynced seja copiado,
 * o update:finish não enfileira job (guard de status ENABLED no hook).
 */
class LegacyOpportunityDuplicateTest extends TestCase
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

    /**
     * Aceita os termos LGPD para o usuário atual em memória.
     *
     * acceptTerms() do módulo acessa $app->request (IP/UserAgent) que ainda não está
     * inicializado no contexto de teste. Por isso replicamos a lógica manualmente,
     * apenas definindo os metadados no __createdMetadata do usuário — suficiente para
     * que o hook GET(<<*>>):before encontre os hashes e não bloqueie a requisição.
     */
    private function acceptLGPDTerms(): void
    {
        $user = $this->app->user;
        if ($user->is('guest')) {
            return;
        }
        $config = $this->app->config['module.LGPD'] ?? [];
        if (empty($config)) {
            return;
        }
        $this->app->disableAccessControl();
        foreach ($config as $slug => $value) {
            $hash = \LGPD\Module::createHash($value['text']);
            $metaKey = "lgpd_{$slug}";
            $current = $user->$metaKey ?: (object)[];
            if (!isset($current->$hash)) {
                $current->$hash = (object)[
                    'timestamp' => time(),
                    'md5' => $hash,
                    'text' => $value['text'],
                    'ip' => '127.0.0.1',
                    'userAgent' => 'test',
                ];
                $user->$metaKey = $current;
            }
        }
        $this->app->enableAccessControl();
    }

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

    private function createOpportunity(string $name = 'Oportunidade Original'): Opportunity
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->acceptLGPDTerms();
        $this->fillRequiredProfileFields($user->profile);
        $this->app->disableAccessControl();
        $className = $user->profile->opportunityClassName;
        /** @var Opportunity $opp */
        $opp = new $className();
        $opp->owner = $user->profile;
        $opp->ownerEntity = $user->profile;
        $opp->name = $name;
        $opp->shortDescription = 'desc';
        $opp->status = Opportunity::STATUS_DRAFT;
        $opp->save(true);
        $this->app->enableAccessControl();
        return $opp;
    }

    private function callDuplicate(Opportunity $opp): void
    {
        $opportunityId = $opp->id;

        $psr7 = (new ServerRequest([], [], "/opportunity/duplicate/{$opportunityId}", 'GET'))
            ->withParsedBody([]);
        $this->app->request = new Request($psr7, 'opportunity', 'duplicate', ['id' => $opportunityId]);
        $this->app->response = new Response();

        $controller = $this->app->controller('opportunity');
        $controller->setRequestData(['id' => $opportunityId]);

        try {
            $controller->callAction('GET', 'duplicate', ['id' => $opportunityId]);
        } catch (Halt) {
        }
    }

    private function findCopy(Opportunity $original): ?Opportunity
    {
        $originalId = $original->id;
        $conn = $this->app->em->getConnection();
        $row = $conn->fetchAssociative(
            "SELECT id FROM opportunity WHERE name LIKE '%[Cópia]%' AND id != :id ORDER BY id DESC LIMIT 1",
            ['id' => $originalId]
        );
        if (!$row) {
            return null;
        }
        $this->app->em->clear();
        $this->app->disableAccessControl();
        $copy = $this->app->repo('Opportunity')->find($row['id']);
        $this->app->enableAccessControl();
        return $copy;
    }

    private function findUpdateJob(int $opportunityId): mixed
    {
        $internalId = "oportunidade-cult-update:{$opportunityId}";
        $hashedId = md5("oportunidade-cult:{$internalId}");
        return $this->app->repo('Job')->findOneBy(['id' => $hashedId]);
    }

    // ===== Status, nome e integridade básica =====

    /**
     * A cópia criada por duplicate sempre começa em STATUS_DRAFT.
     *
     * cloneOpportunity() define explicitamente Entity::STATUS_DRAFT = 0.
     * Isso impede que o update:finish enfileire job de integração imediatamente.
     */
    function testDuplicarCriaCopiaEmStatusDraft()
    {
        $original = $this->createOpportunity();
        $this->callDuplicate($original);

        $copy = $this->findCopy($original);

        $this->assertNotNull($copy, 'A duplicação deve criar uma nova oportunidade');
        $this->assertSame(
            Opportunity::STATUS_DRAFT,
            (int) $copy->status,
            'A cópia deve ter status DRAFT'
        );
    }

    /**
     * O nome da cópia deve conter o sufixo " - [Cópia][dd-mm-yyyy hh:mm:ss]".
     *
     * Isso diferencia visualmente a cópia do original e informa a data da duplicação.
     */
    function testDuplicarNomeTemSufixoCopia()
    {
        $original = $this->createOpportunity('Minha Oportunidade');
        $this->callDuplicate($original);

        $copy = $this->findCopy($original);

        $this->assertNotNull($copy);
        $this->assertStringContainsString('Minha Oportunidade', $copy->name, 'Nome original deve estar na cópia');
        $this->assertStringContainsString('[Cópia]', $copy->name, 'Nome deve conter [Cópia]');
        // Formato: dd-mm-yyyy hh:mm:ss dentro de colchetes
        $this->assertMatchesRegularExpression(
            '/\[\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2}\]/',
            $copy->name,
            'Nome deve conter timestamp no formato [dd-mm-yyyy hh:mm:ss]'
        );
    }

    /**
     * Duplicar não altera o original.
     *
     * O original deve permanecer com o mesmo nome, status e metadados após a duplicação.
     */
    function testDuplicarNaoAlteraOriginal()
    {
        $original = $this->createOpportunity('Original Intacto');
        $originalId = $original->id;
        $originalName = $original->name;
        $originalStatus = (int) $original->status;

        $this->app->disableAccessControl();
        $original->setMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED, '1');
        $original->save(true);
        $this->app->enableAccessControl();

        $this->callDuplicate($original);

        $this->app->em->clear();

        $this->app->disableAccessControl();
        $reloaded = $this->app->repo('Opportunity')->find($originalId);
        $this->app->enableAccessControl();

        $this->assertSame($originalName, $reloaded->name, 'Nome do original não deve ser alterado');
        $this->assertSame($originalStatus, (int) $reloaded->status, 'Status do original não deve ser alterado');

        $this->app->disableAccessControl();
        $syncedFlag = $reloaded->getMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED);
        $this->app->enableAccessControl();

        $this->assertSame('1', $syncedFlag, 'Metadado do original não deve ser alterado pela duplicação');
    }

    // ===== Cópia de metadados =====

    /**
     * duplicateMetadata() copia todos os metadados não-nulos do original para a cópia.
     *
     * Diferente de generateMetadata(), não há exclusão de chaves — todos os metadados
     * são copiados. Isso inclui shortDescription, parActions, etc.
     */
    function testDuplicarCopiaMetadados()
    {
        $original = $this->createOpportunity();

        $this->app->disableAccessControl();
        $original->setMetadata('federativeEntityId', '42');
        $original->save(true);
        $this->app->enableAccessControl();

        $this->callDuplicate($original);

        $copy = $this->findCopy($original);
        $this->assertNotNull($copy, 'A duplicação deve criar uma nova oportunidade');

        $this->app->disableAccessControl();
        $value = $copy->getMetadata('federativeEntityId');
        $this->app->enableAccessControl();

        $this->assertSame('42', $value, 'federativeEntityId deve ser copiado para a duplicata');
    }

    /**
     * duplicate COPIA as flags de integração CultBr — diferente de generateopportunity.
     *
     * generateopportunity EXCLUI cultBrCreateSynced e isGeneratedFromModel via hook.
     * duplicate não tem essa exclusão: copia tudo. Isso é intencional — a cópia é
     * uma réplica fiel do original para ser editada pelo mesmo gestor.
     */
    function testDuplicarCopiaFlagsDeIntegracao()
    {
        $original = $this->createOpportunity();

        $this->app->disableAccessControl();
        $original->setMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED, '1');
        $original->setMetadata(Controller::OPPORTUNITY_META_IS_GENERATED_FROM_MODEL, '1');
        $original->save(true);
        $this->app->enableAccessControl();

        $this->callDuplicate($original);

        $copy = $this->findCopy($original);
        $this->assertNotNull($copy, 'A duplicação deve criar uma nova oportunidade');

        $this->app->disableAccessControl();
        $cultBrSynced = $copy->getMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED);
        $isGenerated = $copy->getMetadata(Controller::OPPORTUNITY_META_IS_GENERATED_FROM_MODEL);
        $this->app->enableAccessControl();

        $this->assertSame(
            '1',
            $cultBrSynced,
            'cultBrCreateSynced DEVE ser copiado pelo duplicate (diferente de generateopportunity)'
        );
        $this->assertSame(
            '1',
            $isGenerated,
            'isGeneratedFromModel DEVE ser copiado pelo duplicate (diferente de generateopportunity)'
        );
    }

    // ===== Integração CultBr =====

    /**
     * A cópia em STATUS_DRAFT não deve enfileirar job de update CultBr.
     *
     * O hook update:finish só enfileira quando status === STATUS_ENABLED.
     * Como a cópia começa em DRAFT, mesmo que tenha cultBrCreateSynced='1', não há job.
     */
    function testDuplicarNaoEnfileiraJobCultBrQuandoStatusDraft()
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->acceptLGPDTerms();
        $this->fillRequiredProfileFields($user->profile);
        $this->app->disableAccessControl();
        $className = $user->profile->opportunityClassName;

        $original = new $className();
        $original->owner = $user->profile;
        $original->ownerEntity = $user->profile;
        $original->name = 'Original Para Duplicar';
        $original->shortDescription = 'desc';
        $original->status = Opportunity::STATUS_DRAFT;
        $original->save(true);

        $subsite = new Subsite();
        $subsite->name = 'Subsite Dup Test';
        $subsite->url = 'subsite-dup-' . uniqid();
        $subsite->save(true);
        $_ENV['ALDIRBLANC_SUBSITE_ID'] = (string) $subsite->id;

        $original->subsite = $subsite;
        $original->setMetadata('federativeEntityId', '1');
        $original->setMetadata(Controller::OPPORTUNITY_META_IS_GENERATED_FROM_MODEL, '1');
        $original->setMetadata(Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED, '1');
        $original->save(true);
        $this->app->enableAccessControl();

        $this->callDuplicate($original);

        $copy = $this->findCopy($original);
        $this->assertNotNull($copy);

        $this->assertSame(
            Opportunity::STATUS_DRAFT,
            (int) $copy->status,
            'Cópia deve estar em DRAFT'
        );

        $updateJob = $this->findUpdateJob($copy->id);
        $this->assertNull(
            $updateJob,
            'Cópia em STATUS_DRAFT não deve gerar job de update CultBr, mesmo com cultBrCreateSynced=1'
        );
    }

    // ===== Contraste com generateopportunity =====

    /**
     * Contraste explícito: duplicate copia flags CultBr; generateopportunity não.
     *
     * generateopportunity registra exclusão via hook EntityManagerModel.generateMetadata.excludedKeys
     * no Plugin AldirBlanc. duplicate não tem essa exclusão.
     *
     * Este teste documenta a diferença de comportamento para garantir que nenhuma
     * refatoração futura homogenize erroneamente os dois fluxos.
     */
    function testDuplicarVsUsarModeloFlagsCultBr()
    {
        // Verificar que o hook de exclusão está registrado
        $hookName = 'EntityManagerModel.generateMetadata.excludedKeys';
        $handlers = $this->app->getHooks($hookName);

        $this->assertNotEmpty(
            $handlers,
            "O hook '{$hookName}' deve estar registrado pelo Plugin AldirBlanc para excluir flags de integração"
        );

        // O duplicate NÃO usa esse hook — ele copia tudo via duplicateMetadata()
        // O generateopportunity usa generateMetadata() que chama applyHookBoundTo com esse hook
        $excludedKeys = [];
        $this->app->applyHookBoundTo($this, $hookName, [&$excludedKeys]);

        $this->assertContains(
            Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED,
            $excludedKeys,
            'generateopportunity deve excluir cultBrCreateSynced via hook (duplicate não faz isso)'
        );
        $this->assertContains(
            Controller::OPPORTUNITY_META_IS_GENERATED_FROM_MODEL,
            $excludedKeys,
            'generateopportunity deve excluir isGeneratedFromModel via hook (duplicate não faz isso)'
        );
    }
}
