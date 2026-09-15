<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Entities\FederativeEntity;
use AldirBlanc\Services\FederativeEntityService;
use Tests\Abstract\TestCase;

/**
 * FederativeEntityService::hasParData — estado do PAR lido da entidade.
 *
 * É o que alimenta o banner do ente selecionado, cuja sessão guarda só id, nome e documento.
 */
class FederativeEntityServiceParDataTest extends TestCase
{
    private function federativeEntity(string $document, array $exercices): FederativeEntity
    {
        $this->app->disableAccessControl();
        $entity = new FederativeEntity();
        $entity->name = 'Ente ' . $document;
        $entity->document = $document;
        $entity->exercices = $exercices;
        $entity->createTimestamp = new \DateTime();
        $this->app->em->persist($entity);
        $this->app->em->flush();
        $this->app->enableAccessControl();
        return $entity;
    }

    function testEnteComExerciciosTemParData()
    {
        $entity = $this->federativeEntity('91111111111111', [['id' => 1, 'ano' => 2025, 'metas' => []]]);

        $this->assertTrue(FederativeEntityService::hasParData($entity->id));
    }

    function testEnteSemExerciciosNaoTemParData()
    {
        $entity = $this->federativeEntity('92222222222222', []);

        $this->assertFalse(FederativeEntityService::hasParData($entity->id));
    }

    function testIdInexistenteNaoTemParData()
    {
        $this->assertFalse(FederativeEntityService::hasParData(999999999));
    }

    function testIdInvalidoNaoTemParData()
    {
        $this->assertFalse(FederativeEntityService::hasParData(0));
    }
}
