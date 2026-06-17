<?php

namespace AldirBlanc\Enum;

class Role
{
    public const GESTOR_CULT_BR = 'GestorCultBr';
    public const SAAS_SUPER_ADMIN = 'saasSuperAdmin';
    public const SAAS_ADMIN = 'saasAdmin';
    public const SUPER_ADMIN = 'superAdmin';
    public const ADMIN = 'admin';

    public const ROLES_ALLOWED = [
        self::GESTOR_CULT_BR,
        self::SAAS_SUPER_ADMIN,
        self::SAAS_ADMIN,
        self::SUPER_ADMIN,
        self::ADMIN
    ];

    public const ADMIN_ROLES = [
        self::SAAS_SUPER_ADMIN,
        self::SAAS_ADMIN,
        self::SUPER_ADMIN,
        self::ADMIN
    ];

    public const SUPER_SAAS_ADMIN_ROLES = [
        self::SAAS_SUPER_ADMIN,
        self::SAAS_ADMIN,
    ];

    public const CAN_ASSOCIATE_ACTION_PAR_ROLES = [
        self::SAAS_SUPER_ADMIN,
        self::ADMIN,
    ];
}
