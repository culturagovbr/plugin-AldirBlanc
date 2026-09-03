<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Entities\FederativeEntity;
use Pnab\Services\FederativeEntityAdminService;
use Tests\Abstract\TestCase;

/**
 * FederativeEntityAdminService::getViewData — estado do PAR na listagem do saasSuperAdmin.
 *
 * É o que decide o badge "Dados do PAR ausentes" em cada card.
 */
class ThemeFederativeEntityAdminServiceTest extends TestCase
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

    private function itemFor(string $document): ?array
    {
        $service = new FederativeEntityAdminService($this->app);

        foreach ($service->getViewData()['entities'] as $item) {
            if (($item['document'] ?? null) === $document) {
                return $item;
            }
        }

        return null;
    }

    function testEnteComExerciciosNaoEhMarcadoComoSemPar()
    {
        $this->federativeEntity('71111111111111', [['id' => 1, 'ano' => 2025, 'metas' => []]]);

        $this->assertTrue($this->itemFor('71111111111111')['has_par_data']);
    }

    function testEnteSemExerciciosEhMarcadoComoSemPar()
    {
        $this->federativeEntity('72222222222222', []);

        $this->assertFalse($this->itemFor('72222222222222')['has_par_data']);
    }
}
