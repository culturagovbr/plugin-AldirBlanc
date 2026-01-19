<?php

namespace AldirBlanc;

use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Jobs\GestorCultJob;
use AldirBlanc\Services\SyncGestor;
use AldirBlanc\Services\UserAccessService;
use AldirBlanc\Services\UserService;
use MapasCulturais\App;
use MapasCulturais\Traits\RegisterFunctions;
use MapasCulturais\i;
use AldirBlanc\Traits\DoctrineEventListenerTrait;

class Plugin extends \MapasCulturais\Plugin
{
    use RegisterFunctions;
    use DoctrineEventListenerTrait;

    protected static $instance;

    function __construct($config = [])
    {
        $config += [
            'client' => [
                'mode' => env('ALDIRBLANC_CULTBR_MODE', 'development'),
                'host' => env('ALDIRBLANC_CULTBR_HOST', null),
                'token' => env('ALDIRBLANC_CULTBR_TOKEN', null),
                'gestorEndpoint' => env('ALDIRBLANC_CULTBR_GESTOR_ENDPOINT', null),
                'enteFederadoEndpoint' => env('ALDIRBLANC_CULTBR_ENTE_FEDERADO_ENDPOINT', null),
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

        $app->hook('auth.login', function($user) use($app){
            /** @var UserAccessService $user */

            if (UserAccessService::isGestorCultBr()) {
                $gestorDocument = new GestorDocument((new UserService())->getCpf());
                (new GestorCultJob($gestorDocument))->sync();
            }
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
            "AldirBlanc\Entities\FederativeEntity" => "AldirBlanc\Entities\FederativeEntityAgentRelation",
        ];
    }
}
