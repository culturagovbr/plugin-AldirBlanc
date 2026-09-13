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
     * Mantém o primeiro de cada identidade, nunca agrupando por nome — há homônimos com CNPJ distinto.
     * @param list<mixed> $entes
     * @return list<mixed>
     */
    public static function dedupe(array $entes): array
    {
        $resultado = [];
        $vistos = [];

        foreach ($entes as $ente) {
            // Item torto segue adiante: quem descarta e registra o motivo é a validação de contrato.
            if (!is_array($ente) || !isset($ente['document']) || trim((string) $ente['document']) === '') {
                $resultado[] = $ente;
                continue;
            }

            $documento = self::normalize((string) $ente['document']);

            if (isset($vistos[$documento])) {
                continue;
            }

            $vistos[$documento] = true;
            $ente['document'] = $documento;
            $resultado[] = $ente;
        }

        return $resultado;
    }
}
