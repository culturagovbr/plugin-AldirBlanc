<?php

namespace AldirBlanc\Enum;

/** Se a integração fala com a API ou devolve fixture. O valor vem da configuração do plugin. */
enum Mode: string
{
    case Live = 'live';
    case Development = 'development';

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }
}
