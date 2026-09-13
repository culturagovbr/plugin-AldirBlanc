<?php

namespace AldirBlanc\Integration;

use AldirBlanc\Dtos\FederativeEntitySnapshot;
use AldirBlanc\Dtos\ManagerSnapshot;
use AldirBlanc\Exceptions\IntegrationError;

/**
 * Traduz o retorno do gestor para o contrato. Vive fora das implementações porque as duas
 * APIs usam os mesmos nomes de campo: se cada uma trouxesse a própria cópia, bastaria uma
 * divergir para o sync associar os entes errados.
 */
final class ManagerSnapshotMapper
{
    /** Chave na resposta da API => campo do snapshot. */
    private const FIELDS = [
        'nome' => 'name',
        'rg' => 'rg',
        'cep' => 'cep',
        'celular' => 'cellphone',
        'numero' => 'number',
        'complemento' => 'complement',
    ];

    /** @param bool $acceptFlatList a Gestão devolve a lista nua em respostas antigas; a Conecta não. */
    public static function fromResponse(mixed $resposta, bool $acceptFlatList): ManagerSnapshot
    {
        if (!is_array($resposta)) {
            throw IntegrationError::contract('resposta do gestor não é um objeto');
        }

        $campos = [];

        foreach (self::FIELDS as $naApi => $noContrato) {
            if (array_key_exists($naApi, $resposta)) {
                $campos[$noContrato] = $resposta[$naApi] === null ? null : (string) $resposta[$naApi];
            }
        }

        return new ManagerSnapshot($campos, self::entities($resposta, $acceptFlatList));
    }

    private static function entities(array $resposta, bool $acceptFlatList): array
    {
        if (array_key_exists('entes_federados', $resposta)) {
            if (!is_array($resposta['entes_federados'])) {
                throw IntegrationError::contract('entes_federados deve ser array');
            }

            $lista = $resposta['entes_federados'];
        } elseif ($acceptFlatList && array_is_list($resposta)) {
            $lista = $resposta;
        } else {
            throw IntegrationError::contract('chave entes_federados ausente');
        }

        return array_values(array_map(
            fn(array $ente) => new FederativeEntitySnapshot(
                name: (string) ($ente['name'] ?? ''),
                document: (string) ($ente['document'] ?? ''),
                // A API escreve "exercicios"; a coluna se chama "exercices", e a troca é da fronteira.
                exercices: is_array($ente['exercicios'] ?? null) ? $ente['exercicios'] : [],
            ),
            array_filter($lista, 'is_array'),
        ));
    }
}
