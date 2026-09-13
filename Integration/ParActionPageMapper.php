<?php

namespace AldirBlanc\Integration;

use AldirBlanc\Dtos\ParAction;
use AldirBlanc\Dtos\ParActionPage;
use AldirBlanc\Exceptions\IntegrationError;

/** O envelope do catálogo é idêntico nos dois contratos, campo a campo. */
final class ParActionPageMapper
{
    public static function fromResponse(mixed $resposta, int $skip, int $limit): ParActionPage
    {
        if (!is_array($resposta) || !is_array($resposta['data'] ?? null)) {
            throw IntegrationError::contract('catálogo do PAR sem a chave data');
        }

        $paginacao = is_array($resposta['pagination'] ?? null) ? $resposta['pagination'] : [];
        $itens = array_values(array_map(
            fn(array $acao) => ParAction::fromArray($acao),
            array_filter($resposta['data'], 'is_array'),
        ));

        return new ParActionPage(
            items: $itens,
            skip: (int) ($paginacao['skip'] ?? $skip),
            limit: (int) ($paginacao['limit'] ?? $limit),
            total: (int) ($paginacao['total'] ?? count($itens)),
        );
    }
}
