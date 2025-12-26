<?php

namespace AldirBlanc;

use MapasCulturais\App;
use AldirBlanc\Traits\DoctrineEventListenerTrait;

class Plugin extends \MapasCulturais\Plugin
{
    use DoctrineEventListenerTrait;

    public function _init()
    {
        // Inicializa os mapeamentos do Doctrine para a entidade FederativeEntity
        $this->initDoctrineMappings();
    }

    function register()
    {
        $app = App::i();
        
        // Registra o controller
        $app->registerController('aldirblanc', Controller::class);
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
