<?php

namespace AldirBlanc\Enum;

/** O que o envio pretende na origem. Na Gestão o PUT cria; na Conecta, criar exige POST. */
enum SendAction: string
{
    case Create = 'create';
    case Update = 'update';
}
