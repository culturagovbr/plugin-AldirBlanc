<?php

namespace AldirBlanc\Enum;

/** Qual API atende a integração. O valor vai para a configuração e para o log de envio. */
enum Provider: string
{
    case Gestao = 'gestao';
    case Conecta = 'conecta';
}
