<?php

namespace AldirBlanc\Services;

/** Por que uma resposta não serve: inválida não é gravada, incompleta fica gravada como pendência. */
final class FormularioVeredito
{
    private function __construct(public readonly bool $isInvalida, public readonly string $motivo)
    {
    }

    public static function invalida(string $motivo): self
    {
        return new self(true, $motivo);
    }

    public static function incompleta(string $motivo): self
    {
        return new self(false, $motivo);
    }

    /** O mesmo veredito, dizendo a que questão, linha ou coluna se refere. */
    public function comNome(string $nome): self
    {
        return $nome === '' ? $this : new self($this->isInvalida, "{$nome}: {$this->motivo}");
    }

    /** @param list<?self> $vereditos a primeira inválida ou, sem nenhuma, a primeira incompleta */
    public static function maisGrave(array $vereditos): ?self
    {
        $vereditos = array_values(array_filter($vereditos));
        $invalidos = array_values(array_filter($vereditos, fn(self $veredito) => $veredito->isInvalida));

        return $invalidos[0] ?? $vereditos[0] ?? null;
    }
}
