<?php

namespace AldirBlanc\Helpers;

use MapasCulturais\App;
use Apps\JWTAuthProvider;
use Apps\Entities\UserApp;

class IntegrationTokenHelper
{
    public static function validateOrFail(): void
    {
        $app = App::i();

        if (!$app->auth instanceof JWTAuthProvider) {
            $app->controller('aldirblanc')->json(
                ['error' => true, 'message' => 'Token de integração ausente (Authorization: Bearer)'],
                401
            );
            return;
        }

        $userApp = $app->auth->getUserApp();
        if (!$userApp) {
            $app->controller('aldirblanc')->json(
                ['error' => true, 'message' => 'Token de integração ausente (Authorization: Bearer)'],
                401
            );
            return;
        }

        $integrationUserApp = self::getIntegrationUserApp();
        if (!$integrationUserApp) {
            $app->controller('aldirblanc')->json(
                ['error' => true, 'message' => 'Token de integração não configurado'],
                500
            );
            return;
        }

        if ($userApp->publicKey !== $integrationUserApp->publicKey) {
            $app->controller('aldirblanc')->json(
                ['error' => true, 'message' => 'Token de integração inválido'],
                401
            );
            return;
        }
    }

    /**
     * Retorna o UserApp de integração (appName + subsiteId da config), com cache.
     * @return UserApp|null
     */
    private static function getIntegrationUserApp(): ?UserApp
    {
        $app = App::i();

        $appName = $app->plugins['AldirBlanc']->config['integration']['appName'] ?? null;
        $subsiteId = (int) ($app->plugins['AldirBlanc']->config['integration']['subsiteId'] ?? null);
        $cacheTTL = (int) ($app->plugins['AldirBlanc']->config['integration']['cacheTTL']);

        $cacheKey = "aldirblanc:integration_token:{$appName}:{$subsiteId}";
        if ($app->cache->contains($cacheKey)) {
            return $app->cache->fetch($cacheKey);
        }

        $dql = "
            SELECT u
            FROM Apps\Entities\UserApp u
            WHERE u.name = :appName
            AND u._subsiteId = :subsiteId
            AND u.status = 1
        ";
        $query = $app->em->createQuery($dql)
            ->setParameter('appName', $appName)
            ->setParameter('subsiteId', $subsiteId)
            ->setMaxResults(1);
        $userApp = $query->getOneOrNullResult();

        if ($userApp) {
            $app->cache->save($cacheKey, $userApp, $cacheTTL);
        }

        return $userApp;
    }
}
