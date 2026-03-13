<?php

namespace AldirBlanc\Controllers;

use MapasCulturais\App;
use MapasCulturais\Entities\Registration;
use OpportunityWorkplan\Entities\Workplan as EntitiesWorkplan;
use OpportunityWorkplan\Entities\Goal;
use OpportunityWorkplan\Entities\Delivery;
use OpportunityWorkplan\Services\WorkplanService as CoreWorkplanService;
use OpportunityWorkplan\Controllers\Workplan as CoreWorkplan;

/**
 * Usa o WorkplanService do core e, em seguida, aplica os metadados
 * específicos do Aldir Blanc (culturalMakingStageOther, typeDeliveryOther)
 * registrados no Plugin.php, evitando duplicar a lógica do service.
 */
class Workplan extends CoreWorkplan
{
    public function POST_save()
    {
        $this->requireAuthentication();

        $app = App::i();

        $app->disableAccessControl();

        if (!$this->data['registrationId']) {
            $app->pass();
        }

        $registration = $app->repo(Registration::class)->find($this->data['registrationId']);
        $workplan = $app->repo(EntitiesWorkplan::class)->findOneBy(['registration' => $registration->id]);

        $app->em->beginTransaction();
        try {
            $workplanService = new CoreWorkplanService();
            $workplan = $workplanService->save($registration, $workplan, $this->data);
            $this->applyAldirBlancWorkplanMetadata($workplan, $this->data);
            $app->em->commit();
        } catch (\Exception $e) {
            $app->em->rollback();
            $this->json(['error' => $e->getMessage()], 400);
        }

        $app->enableAccessControl();

        $this->json([
            'workplan' => $workplan->jsonSerialize(),
        ]);
    }

    /**
     * Aplica os metadados do Aldir Blanc (Outros/especificar) em goals e deliveries
     * que o core WorkplanService não persiste.
     */
    private function applyAldirBlancWorkplanMetadata(EntitiesWorkplan $workplan, array $data): void
    {
        $goalsData = $data['workplan']['goals'] ?? [];
        if (empty($goalsData)) {
            return;
        }

        $goalsById = [];
        $goalIdsFromData = [];
        foreach ($workplan->goals as $goal) {
            $goalsById[$goal->id] = $goal;
        }
        foreach ($goalsData as $g) {
            if (!empty($g['id'])) {
                $goalIdsFromData[$g['id']] = true;
            }
        }
        $newGoalIds = array_values(array_diff(array_keys($goalsById), array_keys($goalIdsFromData)));
        sort($newGoalIds);
        $newGoalIndex = 0;

        foreach ($goalsData as $g) {
            $goal = null;
            if (!empty($g['id']) && isset($goalsById[$g['id']])) {
                $goal = $goalsById[$g['id']];
            } elseif (isset($newGoalIds[$newGoalIndex])) {
                $goal = $goalsById[$newGoalIds[$newGoalIndex]];
                $newGoalIndex++;
            }
            if (!$goal) {
                continue;
            }

            $goal->culturalMakingStageOther = $g['culturalMakingStageOther'] ?? null;
            $goal->save(true);

            $deliveriesData = $g['deliveries'] ?? [];
            $deliveriesById = [];
            $deliveryIdsFromData = [];
            foreach ($goal->deliveries as $d) {
                $deliveriesById[$d->id] = $d;
            }
            foreach ($deliveriesData as $d) {
                if (!empty($d['id'])) {
                    $deliveryIdsFromData[$d['id']] = true;
                }
            }
            $newDeliveryIds = array_values(array_diff(array_keys($deliveriesById), array_keys($deliveryIdsFromData)));
            sort($newDeliveryIds);
            $newDeliveryIndex = 0;

            foreach ($deliveriesData as $d) {
                $delivery = null;
                if (!empty($d['id']) && isset($deliveriesById[$d['id']])) {
                    $delivery = $deliveriesById[$d['id']];
                } elseif (isset($newDeliveryIds[$newDeliveryIndex])) {
                    $delivery = $deliveriesById[$newDeliveryIds[$newDeliveryIndex]];
                    $newDeliveryIndex++;
                }
                if (!$delivery) {
                    continue;
                }
                $delivery->typeDeliveryOther = $d['typeDeliveryOther'] ?? null;
                $delivery->save(true);
            }
        }
    }
}

