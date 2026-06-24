<?php

namespace AldirBlanc\Services;

use AldirBlanc\Enum\Role;
use AldirBlanc\Entities\FederativeEntityAgentRelation;
use MapasCulturais\App;

class UserAccessService
{
    /**
     * Verifica se o usuário é um gestor Cult
     * 
     * @return bool
     */
    public static function isGestorCultBr(): bool
    {
        return App::i()->user->is(Role::GESTOR_CULT_BR);
    }

    /**
     * Verifica se o usuário é saasSuperAdmin (pode ver "Meus Aplicativos")
     *
     * @return bool
     */
    public static function isSaasSuperAdmin(): bool
    {
        return App::i()->user->is(Role::SAAS_SUPER_ADMIN);
    }

    public static function canAssociatePARAction(): bool
    {
        $user = App::i()->user;

        return (bool) array_filter(Role::CAN_ASSOCIATE_ACTION_PAR_ROLES, function ($role) use ($user) {
            return $user->is($role);
        });
    }

    /**
     * Verifica se o usuário é um administrador
     * 
     * @return bool
     */
    public static function isAdmin(): bool
    {
        $user = App::i()->user;

        return (bool) array_filter(Role::ADMIN_ROLES, function ($role) use ($user) {
            return $user->is($role);
        });
    }

    /**
     * Verifica se o usuário atual da aplicação tem acesso baseado nas roles permitidas
     * 
     * @return bool
     */
    public static function canAccess(): bool
    {
        $user = App::i()->user;

        return (bool) array_filter(Role::ROLES_ALLOWED, function ($role) use ($user) {
            return $user->is($role);
        });
    }

    /**
     * Verifica se o usuário atual pode ver a equipe (agentes) de um Ente Federado específico:
     * admins podem ver a de qualquer ente; GestorCultBr só a do(s) ente(s) com que tem vínculo real
     * (FederativeEntityAgentRelation), nunca por confiar num ID arbitrário vindo de query string.
     */
    public static function canViewFederativeEntityTeam(int $federativeEntityId): bool
    {
        if (self::isAdmin()) {
            return true;
        }

        if (!self::isGestorCultBr()) {
            return false;
        }

        $agent = App::i()->user->profile;
        if (!$agent) {
            return false;
        }

        return (bool) App::i()->repo(FederativeEntityAgentRelation::class)->findOneBy([
            'agent' => $agent,
            'owner' => $federativeEntityId,
        ]);
    }
}
