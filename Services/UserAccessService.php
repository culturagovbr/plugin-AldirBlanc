<?php

namespace AldirBlanc\Services;

use AldirBlanc\Enum\Role;
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
}
