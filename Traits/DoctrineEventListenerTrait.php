<?php

namespace AldirBlanc\Traits;

use MapasCulturais\App;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;

/**
 * Trait para estender o DiscriminatorMap do AgentRelation via eventos do Doctrine
 */
trait DoctrineEventListenerTrait
{
    /**
     * Inicializa o listener do Doctrine para estender o DiscriminatorMap do AgentRelation
     * 
     * @param App $app
     * @return void
     */
    protected function initAgentRelationListener(App $app)
    {
        // Tenta registrar o listener imediatamente se o EntityManager estiver disponível
        $this->registerDoctrineListener($app);

        // Também registra usando hook para garantir que seja executado quando o EntityManager estiver pronto
        $self = $this;
        $app->hook('app.init:after', function () use ($self, $app) {
            $self->registerDoctrineListener($app);
        });
    }

    /**
     * Registra o listener do Doctrine para modificar os metadados do AgentRelation
     * 
     * @param App $app
     * @return void
     */
    protected function registerDoctrineListener(App $app)
    {
        // Verifica se o EntityManager está disponível
        if (!$app->em) {
            return;
        }

        $eventManager = $app->em->getEventManager();

        // Verifica se o listener já foi registrado para evitar duplicação
        $listeners = $eventManager->getListeners(Events::loadClassMetadata);
        if (in_array($this, $listeners, true)) {
            return;
        }

        // Adiciona o listener para modificar os metadados quando AgentRelation for carregado
        $eventManager->addEventListener(Events::loadClassMetadata, $this);
    }

    /**
     * Retorna os novos mapeamentos a serem adicionados ao DiscriminatorMap do AgentRelation
     * 
     * Formato: "NomeCompletoDaClasse" => "NomeCompletoDaClasseAgentRelation"
     * 
     * IMPORTANTE: Se você adicionar uma nova entidade aqui, também precisa adicionar
     * o nome da classe ao ENUM 'object_type' no banco de dados via db-updates.php
     * 
     * Exemplo no db-updates.php:
     * ALTER TYPE object_type ADD VALUE 'Pnab\Entities\FederativeEntity';
     * 
     * Sobrescreva este método na classe que usa a trait para retornar seus mapeamentos
     * 
     * @return array<string, string>
     */
    protected function getAgentRelationMappings()
    {
        return [];
    }

    /**
     * Listener do Doctrine que modifica o DiscriminatorMap em runtime
     * 
     * Este método é chamado quando o Doctrine carrega os metadados de uma classe
     * 
     * @param LoadClassMetadataEventArgs $eventArgs
     * @return void
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $eventArgs)
    {
        $classMetadata = $eventArgs->getClassMetadata();

        // Verifica se é a classe AgentRelation
        $agentRelationClass = 'MapasCulturais\Entities\AgentRelation';
        if ($classMetadata->getName() !== $agentRelationClass) {
            return;
        }

        $newMappings = $this->getAgentRelationMappings();

        if (empty($newMappings)) {
            return;
        }

        // Inicializa o discriminatorMap se não existir
        if (!isset($classMetadata->discriminatorMap) || !is_array($classMetadata->discriminatorMap)) {
            $classMetadata->discriminatorMap = [];
        }

        // Atualiza as subclasses manualmente
        if (!isset($classMetadata->subClasses) || !is_array($classMetadata->subClasses)) {
            $classMetadata->subClasses = [];
        }

        foreach ($newMappings as $entityClass => $relationClass) {
            // Remove barra inicial se existir para normalizar o namespace
            $relationClassNormalized = ltrim($relationClass, '\\');

            // Garante que a classe seja carregada antes de adicionar ao discriminator map
            if (!class_exists($relationClassNormalized)) {
                // Tenta carregar a classe com barra inicial
                if (class_exists('\\' . $relationClassNormalized)) {
                    $relationClassNormalized = '\\' . $relationClassNormalized;
                } else {
                    // Se ainda não existir, pula este mapeamento
                    continue;
                }
            }

            // Adiciona ao discriminator map usando o nome normalizado
            $classMetadata->discriminatorMap[$entityClass] = $relationClassNormalized;

            // Adiciona às subclasses se ainda não estiver
            if (!in_array($relationClassNormalized, $classMetadata->subClasses)) {
                $classMetadata->subClasses[] = $relationClassNormalized;
            }
        }
    }
}

