<?php

namespace Tests\AldirBlanc\Doubles;

/** Cumpre o contrato e mesmo assim não pode ser construída: o `new` precisa virar erro de configuração. */
abstract class ProviderAbstrato extends InMemoryProvider
{
}
