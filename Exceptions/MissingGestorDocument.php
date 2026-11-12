<?php

namespace AldirBlanc\Exceptions;

final class MissingGestorDocument extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('CPF do gestor ausente no perfil');
    }
}
