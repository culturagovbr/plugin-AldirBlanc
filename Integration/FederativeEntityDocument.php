<?php

namespace AldirBlanc\Integration;

/** A identidade do ente: uma implementação divergente apagaria os vínculos da outra grafia. */
final class FederativeEntityDocument
{
    private const LENGTH = 14;

    private const ALPHANUMERIC_CNPJ = '/^[A-Z0-9]{12}[0-9]{2}$/';

    /** Sem pontuação e em maiúsculas; o numérico ganha zeros à esquerda até 14 dígitos, sem truncar o maior. */
    public static function normalize(?string $document): string
    {
        $caracteres = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $document));

        // Ausência continua ausência: virar quatorze zeros passaria por documento válido.
        if ($caracteres === '') {
            return '';
        }

        if (!ctype_digit($caracteres)) {
            return preg_match(self::ALPHANUMERIC_CNPJ, $caracteres) ? $caracteres : '';
        }

        return strlen($caracteres) < self::LENGTH
            ? str_pad($caracteres, self::LENGTH, '0', STR_PAD_LEFT)
            : $caracteres;
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
            // Item torto segue adiante: quem descarta com motivo vem depois — o mapper, se não for array; a validação de contrato, se faltar document.
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
