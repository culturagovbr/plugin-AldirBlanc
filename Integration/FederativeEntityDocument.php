<?php

namespace AldirBlanc\Integration;

/** A identidade do ente: uma implementação divergente apagaria os vínculos da outra grafia. */
final class FederativeEntityDocument
{
    private const LENGTH = 14;

    /** Completa com zeros à esquerda; o que passa de 14 dígitos fica como veio, para não truncar. */
    public static function normalize(?string $document): string
    {
        $digitos = preg_replace('/\D/', '', (string) $document);

        // Ausência continua ausência: virar quatorze zeros passaria por documento válido.
        if ($digitos === '') {
            return '';
        }

        return strlen($digitos) < self::LENGTH
            ? str_pad($digitos, self::LENGTH, '0', STR_PAD_LEFT)
            : $digitos;
    }

    /**
     * Uma identidade por documento normalizado, nunca agrupando por nome — há homônimos com CNPJ distinto.
     * Entre irmãos, sobrevive o que traz a árvore do PAR: descartá-la apagaria o que o gestor precisa.
     * @param list<mixed> $entes
     * @return list<mixed>
     */
    public static function dedupe(array $entes): array
    {
        $resultado = [];
        $posicaoPorDocumento = [];

        foreach ($entes as $ente) {
            // Item torto segue adiante: quem descarta com motivo é a validação de contrato, não o dedupe.
            if (!is_array($ente) || !isset($ente['document']) || trim((string) $ente['document']) === '') {
                $resultado[] = $ente;
                continue;
            }

            $documento = self::normalize((string) $ente['document']);
            $ente['document'] = $documento;

            if (!isset($posicaoPorDocumento[$documento])) {
                $posicaoPorDocumento[$documento] = count($resultado);
                $resultado[] = $ente;
                continue;
            }

            $posicao = $posicaoPorDocumento[$documento];

            if (self::temArvore($ente) && !self::temArvore($resultado[$posicao])) {
                $resultado[$posicao] = $ente;
            }
        }

        return $resultado;
    }

    /** @param array<string, mixed> $ente */
    private static function temArvore(array $ente): bool
    {
        return !empty($ente['exercicios']);
    }
}
