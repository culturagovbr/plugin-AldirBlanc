<?php

namespace AldirBlanc\Integration;

use AldirBlanc\Dtos\CredentialCheck;

/** Segregada de propósito: obrigar quem não sabe validar a responder produz quem finge validar. */
interface ValidatesCredential
{
    public function validateCredential(): CredentialCheck;
}
