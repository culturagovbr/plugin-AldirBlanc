<?php

declare(strict_types=1);

namespace AldirBlanc\Traits;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use MapasCulturais\App;

/**
 * Estende o DiscriminatorMap de AgentRelation em runtime (listener + merge explícito após init).
 *
 * O cache de metadados do Doctrine (PhpFilesAdapter) pode devolver ClassMetadata sem passar
 * novamente pelo fluxo que depende só do evento; o merge explícito na inicialização do plugin
 * garante o mapa antes de qualquer find/hidratação.
 */
trait DoctrineEventListenerTrait
{
    private static bool $agentRelationDoctrineListenerRegistered = false;

    private const AGENT_RELATION_CLASS = 'MapasCulturais\Entities\AgentRelation';

    /**
     * Inicializa o listener do Doctrine para estender o DiscriminatorMap do AgentRelation
     */
    protected function initAgentRelationListener(App $app): void
    {
        $this->registerDoctrineListener($app);

        $self = $this;
        $app->hook('app.init:after', function () use ($self, $app): void {
            $self->registerDoctrineListener($app);
            if ($app->em) {
                $self->ensureAgentRelationDiscriminatorMap($app->em);
            }
        });
    }

    /**
     * Registra o listener uma única vez no EventManager do EM atual.
     */
    protected function registerDoctrineListener(App $app): void
    {
        if (!$app->em || self::$agentRelationDoctrineListenerRegistered) {
            return;
        }

        $app->em->getEventManager()->addEventListener(Events::loadClassMetadata, $this);
        self::$agentRelationDoctrineListenerRegistered = true;
    }

    /**
     * @return array<string, string> owner entity class => relation entity class
     */
    protected function getAgentRelationMappings(): array
    {
        return [];
    }

    /**
     * Aplica o merge no ClassMetadata raiz AgentRelation (idempotente).
     */
    protected function ensureAgentRelationDiscriminatorMap(EntityManager $entityManager): void
    {
        $newMappings = $this->getAgentRelationMappings();
        if ($newMappings === []) {
            return;
        }

        $classMetadata = $entityManager->getClassMetadata(self::AGENT_RELATION_CLASS);
        $this->applyMappingsToAgentRelationMetadata($classMetadata, $newMappings);
    }

    /**
     * @param array<string, string> $newMappings
     */
    private function applyMappingsToAgentRelationMetadata(ClassMetadata $classMetadata, array $newMappings): void
    {
        if ($classMetadata->getName() !== self::AGENT_RELATION_CLASS) {
            return;
        }

        if (!isset($classMetadata->discriminatorMap) || !is_array($classMetadata->discriminatorMap)) {
            $classMetadata->discriminatorMap = [];
        }
        if (!isset($classMetadata->subClasses) || !is_array($classMetadata->subClasses)) {
            $classMetadata->subClasses = [];
        }

        foreach ($newMappings as $entityClass => $relationClass) {
            $relationClassNormalized = ltrim($relationClass, '\\');
            $entityClassNormalized = ltrim($entityClass, '\\');

            if (!class_exists($relationClassNormalized)) {
                if (class_exists('\\' . $relationClassNormalized)) {
                    $relationClassNormalized = '\\' . $relationClassNormalized;
                } else {
                    continue;
                }
            }

            $classMetadata->discriminatorMap[$entityClassNormalized] = $relationClassNormalized;

            if (!in_array($relationClassNormalized, $classMetadata->subClasses, true)) {
                $classMetadata->subClasses[] = $relationClassNormalized;
            }
        }
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $eventArgs): void
    {
        $newMappings = $this->getAgentRelationMappings();
        if ($newMappings === []) {
            return;
        }

        $classMetadata = $eventArgs->getClassMetadata();
        $className = $classMetadata->getName();

        if ($className === self::AGENT_RELATION_CLASS) {
            $this->applyMappingsToAgentRelationMetadata($classMetadata, $newMappings);

            return;
        }

        $relationClasses = array_map(
            static fn (string $relationClass): string => ltrim($relationClass, '\\'),
            array_values($newMappings)
        );

        if (!in_array($className, $relationClasses, true)) {
            return;
        }

        $entityManager = $eventArgs->getEntityManager();
        $rootMetadata = $entityManager->getClassMetadata(self::AGENT_RELATION_CLASS);
        $this->applyMappingsToAgentRelationMetadata($rootMetadata, $newMappings);
    }
}
