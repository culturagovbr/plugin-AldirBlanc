<?php

namespace AldirBlanc\Entities;

use AldirBlanc\Enum\Role;
use MapasCulturais\Entities\User as BaseUser;
use MapasCulturais\App;

class User extends BaseUser
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
