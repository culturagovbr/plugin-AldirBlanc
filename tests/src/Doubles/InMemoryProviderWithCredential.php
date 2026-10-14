<?php

namespace Tests\AldirBlanc\Doubles;

use AldirBlanc\Dtos\CredentialCheck;
use AldirBlanc\Enum\Provider;
use AldirBlanc\Integration\ValidatesCredential;

class InMemoryProviderWithCredential extends InMemoryProvider implements ValidatesCredential
{
    public function __construct(private bool $credencialValida = true)
    {
        parent::__construct();
    }

    public function provider(): Provider
    {
        return Provider::Conecta;
    }

    public function validateCredential(): CredentialCheck
    {
        return $this->credencialValida
            ? CredentialCheck::valid()
            : CredentialCheck::invalid('token recusado pela API');
    }
}
