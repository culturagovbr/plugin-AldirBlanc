<?php

namespace AldirBlanc\Services;

/** Tabela do formulário, com as duas formas que o PAR publica lidas do mesmo jeito. */
final class FormularioTabela
{
    private const MIN_LINHAS_PADRAO = 1;

    /** @param list<array> $colunas */
    private function __construct(
        public readonly array $colunas,
        public readonly int $minLinhas,
        public readonly ?int $maxLinhas,
    ) {
    }

    /** Tabela da questão, `tabela` ou `Tabela Dinâmica`. */
    public static function daQuestao(array $questao): self
    {
        $config = $questao['config'] ?? [];

        return isset($config['tabela']) ? self::simples($config['tabela']) : self::dinamica($config);
    }

    private static function simples(array $tabela): self
    {
        return new self(
            array_map(self::colunaSimples(...), $tabela['colunas'] ?? []),
            (int) ($tabela['minLinhas'] ?? self::MIN_LINHAS_PADRAO),
            isset($tabela['maxLinhas']) ? (int) $tabela['maxLinhas'] : null,
        );
    }

    private static function dinamica(array $config): self
    {
        return new self(
            array_map(self::colunaDinamica(...), $config['columns'] ?? []),
            (int) ($config['minRows'] ?? self::MIN_LINHAS_PADRAO),
            isset($config['maxRows']) ? (int) $config['maxRows'] : null,
        );
    }

    private static function colunaSimples(array $coluna): array
    {
        return [
            'id' => $coluna['id'],
            'nome' => $coluna['nome'] ?? $coluna['id'],
            'tipo' => $coluna['tipo'] ?? '',
            'obrigatoria' => !empty($coluna['obrigatoria']),
            'somenteLeitura' => !empty($coluna['somenteLeitura']),
            'opcoes' => $coluna['opcoes'] ?? [],
            'limiteCaracteres' => $coluna['maxCaracteres'] ?? null,
            'minimo' => $coluna['valorMinimo'] ?? null,
            'maximo' => $coluna['valorMaximo'] ?? null,
            'mascara' => $coluna['mascara'] ?? null,
        ];
    }

    /** Na dinâmica, `max` conta caracteres na coluna de texto e valor nas numéricas. */
    private static function colunaDinamica(array $coluna): array
    {
        return [
            'id' => $coluna['key'],
            'nome' => $coluna['label'] ?? $coluna['key'],
            'tipo' => $coluna['type'] ?? '',
            'obrigatoria' => !empty($coluna['required']),
            'somenteLeitura' => !empty($coluna['readOnly']),
            'opcoes' => $coluna['dropdownOptions']['values'] ?? [],
            'limiteCaracteres' => $coluna['max'] ?? null,
            'minimo' => $coluna['min'] ?? null,
            'maximo' => $coluna['max'] ?? null,
            'mascara' => $coluna['mask'] ?? null,
        ];
    }
}
