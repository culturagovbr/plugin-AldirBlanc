<?php

namespace AldirBlanc\Services;

/** Faixas do edital: cada uma reparte o seu valor pelas vagas que oferece. */
final class FaixasDoEdital
{
    /** @var list<array{label: string, vagas: int, valor: ?float}> */
    private array $faixas = [];

    /** @param mixed $registrationRanges como o edital as guarda: label, limit e value */
    public function __construct(mixed $registrationRanges)
    {
        foreach ((array) $registrationRanges as $faixa) {
            $label = trim((string) ($faixa['label'] ?? ''));
            $valor = $faixa['value'] ?? null;

            if ($label !== '') {
                $this->faixas[] = [
                    'label' => $label,
                    'vagas' => (int) ($faixa['limit'] ?? 0),
                    'valor' => is_numeric($valor) ? (float) $valor : null,
                ];
            }
        }
    }

    /** @return list<string> */
    public function rotulos(): array
    {
        return array_column($this->faixas, 'label');
    }

    /** Valor de cada contemplado da faixa, ou null quando falta o valor ou a vaga. */
    public function valorPorContemplado(string $label): ?float
    {
        $valor = $this->valorExato($label);

        return $valor === null ? null : round($valor, 2);
    }

    /**
     * Valor repassado às contempladas, ou null quando alguma faixa não diz o seu.
     * @param array<string, int> $contempladas por rótulo
     */
    public function totalRepassado(array $contempladas): ?float
    {
        $total = 0.0;

        foreach ($this->rotulos() as $label) {
            $valor = $this->valorExato($label);

            if ($valor === null) {
                return null;
            }

            $total += $valor * ($contempladas[$label] ?? 0);
        }

        return round($total, 2);
    }

    private function valorExato(string $label): ?float
    {
        $indice = array_search($label, $this->rotulos(), true);

        if ($indice === false) {
            return null;
        }

        $faixa = $this->faixas[$indice];

        return $faixa['valor'] === null || $faixa['vagas'] < 1 ? null : $faixa['valor'] / $faixa['vagas'];
    }
}
