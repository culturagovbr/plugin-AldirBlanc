<?php

namespace AldirBlanc;

use MapasCulturais\App;
use MapasCulturais\Traits\RegisterFunctions;
use MapasCulturais\i;
use AldirBlanc\Traits\DoctrineEventListenerTrait;
use AldirBlanc\Jobs\OportunidadeCultJob;

class Plugin extends \MapasCulturais\Plugin
{
    use RegisterFunctions;
    use DoctrineEventListenerTrait;

    protected static $instance;

    function __construct($config = [])
    {
        $config += [
            'client' => [
                'mode' => env('PNAB_CULTBR_MODE', 'development'),
                'host' => env('PNAB_CULTBR_HOST', null),
                'token' => env('PNAB_CULTBR_TOKEN', null),

                // PRIMEIRA FASE DA INTEGRAÇÃO (BUSCAR DADOS DO GESTOR E ENTES FEDERADOS)
                'seficEndpoint' => env('PNAB_CULTBR_SEFIC_ENDPOINT', null),
                'gestorEndpoint' => env('PNAB_CULTBR_GESTOR_ENDPOINT', null),
                'enteFederadoEndpoint' => env('PNAB_CULTBR_ENTE_FEDERADO_ENDPOINT', null),
                'createOportunidadeEndpoint' => env('PNAB_CULTBR_CREATE_OPORTUNIDADE_ENDPOINT', null),
                'updateOportunidadeEndpoint' => env('PNAB_CULTBR_UPDATE_OPORTUNIDADE_ENDPOINT', null),
            ], 
            // Token de integração para consumo do CultBR
            'integration' => [
                'appName' => env('ALDIRBLANC_APPLICATION_NAME', null),
                'subsiteId' => env('ALDIRBLANC_SUBSITE_ID', null),
                'cacheTTL' => env('ALDIRBLANC_INTEGRATION_CACHE_TTL', null),
                'maxRequestsPerDay' => (int) env('ALDIRBLANC_INTEGRATION_MAX_REQUESTS_PER_DAY', 10),
                'delayJob' => env('ALDIRBLANC_INTEGRATION_DELAY_JOB', null),
                'retryDelayJob' => env('ALDIRBLANC_INTEGRATION_RETRY_DELAY_JOB', null),
            ]
        ];

        parent::__construct($config);
        self::$instance = $this;
    }

    /**
     * Retorna a instância do plugin
     * 
     * @return Plugin|null
     */
    public static function getInstance(): ?Plugin
    {
        return self::$instance;
    }

    public function _init()
    {
        // Inicializa os mapeamentos do Doctrine para a entidade FederativeEntity
        $this->initDoctrineMappings();

        $app = App::i();

        // Registra shortcut customizado para API de integração
        $app->config['routes']['shortcuts']['aldirblanc/opportunities'] = [
            'aldirblanc',
            'integrationOpportunities'
        ];

        /**
         * Painel user-detail (tema Pnab): botão super/saas super para limpar cache/metadados de sync CultBR (qualquer usuário com perfil).
         * O callback é executado com applyHookBoundTo(Theme): $this é o tema ativo.
         */
        $app->hook('template(panel.user-detail.user-mail):begin', function () use ($app) {
            if (!class_exists('Pnab\\Theme') || !$app->view instanceof \Pnab\Theme) {
                return;
            }
            /** @var \MapasCulturais\Themes\BaseV2\Theme $this */
            $this->part('panel/gestor-cult-sync-reset');
        }, 55);

        $app->hook('GET(panel.user-detail):before', function () use ($app) {
            if (!class_exists('Pnab\\Theme') || !$app->view instanceof \Pnab\Theme) {
                return;
            }
            $app->view->enqueueStyle(
                'app-v2',
                'aldirblanc-panel-gestor-cult-sync-reset',
                'css/panel-gestor-cult-sync-reset.css',
            );
        });
    }

    function register()
    {
        $app = App::i();
        
        // Registra o controller
        $app->registerController('aldirblanc', Controller::class);

        // Registra metadado federativeEntityId para entidades principais
        // Usa registerMetadata com namespace completo, seguindo o padrão do SpamDetector
        $entities = [
            'MapasCulturais\Entities\Opportunity',
            'MapasCulturais\Entities\Project',
            'MapasCulturais\Entities\Event',
            'MapasCulturais\Entities\Space',
            'MapasCulturais\Entities\Agent'
        ];

        foreach ($entities as $entityClass) {
            $this->registerMetadata($entityClass, 'federativeEntityId', [
                'label' => i::__('ID do Ente Federado'),
                'type' => 'integer',
                'private' => false
            ]);
        }

        // Registra metadado last_synced_at apenas para Agent
        $this->registerMetadata('MapasCulturais\Entities\Agent', 'gestorCultBrLastSyncedAt', [
            'label' => i::__('Data da última sincronização com API Gestor CultBR'),
            'type' => 'DateTime',
            'private' => true
        ]);

        // Registra metadado isNotGestorCultBr: quando a API do Cult não retorna entes, grava true
        // para que no 2º ou N-ésimo login o usuário pule a tela de consolidação
        $this->registerMetadata('MapasCulturais\Entities\Agent', 'isNotGestorCultBr', [
            'label' => i::__('Indica que a API CultBR não retornou entes para este agente'),
            'type' => 'boolean',
            'private' => true
        ]);

        // Registra metadado de data de publicação do edital para Opportunity (usado na integração com Oportunidade Cult)
        $this->registerMetadata('MapasCulturais\Entities\Opportunity', 'publishedTimestamp', [
            'label' => i::__('Data de publicação do edital'),
            'type' => 'DateTime',
            'private' => true,
        ]);

        // PAR: instrumento (exercício -> meta -> ação -> atividade) para vincular oportunidade ao cadastro do ente
        $this->registerMetadata('MapasCulturais\Entities\Opportunity', 'parExercicioId', [
            'label' => i::__('PAR - Exercício (ano)'),
            'type' => 'string',
            'private' => false,
        ]);
        $this->registerMetadata('MapasCulturais\Entities\Opportunity', 'parMetaId', [
            'label' => i::__('PAR - Meta'),
            'type' => 'string',
            'private' => false,
        ]);
        $this->registerMetadata('MapasCulturais\Entities\Opportunity', 'parAcaoId', [
            'label' => i::__('PAR - Ação'),
            'type' => 'string',
            'private' => false,
        ]);
        $this->registerMetadata('MapasCulturais\Entities\Opportunity', 'parAtividadeId', [
            'label' => i::__('PAR - Atividade'),
            'type' => 'string',
            'private' => false,
        ]);

        // Gravado em POST_saveOpportunityPostGenerate; validateIntegrationJob no tema Pnab consulta para subsite.
        $this->registerMetadata('MapasCulturais\Entities\Opportunity', Controller::OPPORTUNITY_META_IS_GENERATED_FROM_MODEL, [
            'label' => i::__('Oportunidade gerada a partir de modelo'),
            'type' => 'string',
            'private' => true,
        ]);

        $this->registerMetadata('MapasCulturais\Entities\Opportunity', Controller::OPPORTUNITY_META_CULT_BR_CREATE_SYNCED, [
            'label' => i::__('Create CultBR já enviado com sucesso'),
            'type' => 'string',
            'private' => true,
        ]);

        $app->registerJobType(new OportunidadeCultJob(OportunidadeCultJob::SLUG));
    }

    /**
     * Inicializa os mapeamentos do Doctrine para a entidade FederativeEntity
     * 
     * @return void
     */
    private function initDoctrineMappings()
    {
        $app = App::i();

        // Garante que a classe AgentRelation seja carregada antes de inicializar o listener
        class_exists(\AldirBlanc\Entities\FederativeEntityAgentRelation::class);

        // Inicializa o listener do Doctrine para estender o DiscriminatorMap do AgentRelation
        $this->initAgentRelationListener($app);

        // Merge explícito: cobre metadados vindos de cache / ordem de carregamento sem o evento
        if ($app->em) {
            $this->ensureAgentRelationDiscriminatorMap($app->em);
        }

        // Registra o valor no ENUM PHP ObjectType
        $app->hook('doctrine.emum(object_type).values', function (&$values) {
            $values['FederativeEntity'] = \AldirBlanc\Entities\FederativeEntity::class;
        });
    }

    /**
     * Retorna os novos mapeamentos a serem adicionados ao DiscriminatorMap do AgentRelation
     * 
     * Formato: "NomeCompletoDaClasse" => "NomeCompletoDaClasseAgentRelation"
     * 
     * IMPORTANTE: Se você adicionar uma nova entidade aqui, também precisa adicionar
     * o nome da classe ao ENUM 'object_type' no banco de dados via db-updates.php
     * 
     * @return array
     */
    protected function getAgentRelationMappings()
    {
        return [
            'AldirBlanc\Entities\FederativeEntity' => 'AldirBlanc\Entities\FederativeEntityAgentRelation',
        ];
    }
}
