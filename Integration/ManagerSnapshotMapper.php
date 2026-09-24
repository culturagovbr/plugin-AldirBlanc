<?php

namespace AldirBlanc\Integration;

use AldirBlanc\Dtos\FederativeEntitySnapshot;
use AldirBlanc\Dtos\ManagerSnapshot;
use AldirBlanc\Exceptions\IntegrationError;
use MapasCulturais\App;

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

    /**
     * @param bool $acceptFlatList a Gestão devolve a lista nua em respostas antigas; a Conecta não.
     * @param string $documento do gestor, só para nomear o dono no registro de descarte.
     */
    public static function fromResponse(mixed $resposta, bool $acceptFlatList, string $documento = ''): ManagerSnapshot
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

        return new ManagerSnapshot($campos, self::entities($resposta, $acceptFlatList, $documento));
    }

    private static function entities(array $resposta, bool $acceptFlatList, string $documento): array
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
            self::apenasArrays(FederativeEntityDocument::dedupe($lista), $documento),
        ));
    }

    /**
     * Descarta o que não é array, porque o construtor do snapshot exige array, e registra cada
     * descarte: sem isso o ente some entre a resposta e o job, que só valida o que chega inteiro.
     *
     * @param list<mixed> $entes
     * @return list<array>
     */
    private static function apenasArrays(array $entes, string $documento): array
    {
        $arrays = [];

        foreach ($entes as $indice => $ente) {
            if (is_array($ente)) {
                $arrays[] = $ente;
                continue;
            }

            $dono = $documento === '' ? 'não informado' : $documento;
            App::i()->log->warning(
                "[Gestores CultBR] Ente federado descartado no mapeamento | Documento: {$dono} | Item: {$indice} | Motivo: deve ser array"
            );
        }

        return $arrays;
    }
}
