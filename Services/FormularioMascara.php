<?php

namespace AldirBlanc\Services;

use Respect\Validation\Rules\Cpf;

/** Máscaras que o PAR declara em questões e colunas, conferidas pelo nome. */
final class FormularioMascara
{
    private const NENHUMA = 'nenhum';
    private const CEP = 'cep';
    private const CPF = 'cpf';
    private const CNPJ = 'cnpj';
    private const DOCUMENTO = 'documento';

    private const TAMANHO_CEP = 8;
    private const TAMANHO_CPF = 11;
    private const TAMANHO_CNPJ = 14;

    private const PESOS_CNPJ = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    private const CONHECIDAS = [self::NENHUMA, self::CEP, self::CPF, self::CNPJ, self::DOCUMENTO];

    /** Há conferência para a máscara com este nome? */
    public static function isConhecida(string $mascara): bool
    {
        return in_array($mascara, self::CONHECIDAS, true);
    }

    /** Documento sem a pontuação da máscara; letra do CNPJ alfanumérico fica, em maiúscula. */
    public static function semPontuacao(string $texto): string
    {
        return strtoupper(preg_replace('/[.\-\/\s]/', '', $texto));
    }

    /** Veredito do texto pela máscara; nome desconhecido é recusado. */
    public static function confere(string $mascara, string $texto): ?FormularioVeredito
    {
        $limpo = self::semPontuacao($texto);

        return match ($mascara) {
            self::NENHUMA => null,
            self::CEP => self::confereForma($limpo, '/^\d*$/', self::TAMANHO_CEP, 'CEP'),
            self::CPF => self::confereCpf($limpo),
            self::CNPJ => self::confereCnpj($limpo),
            self::DOCUMENTO => self::confereDocumento($limpo),
            default => FormularioVeredito::invalida("a máscara \"{$mascara}\" não é reconhecida"),
        };
    }

    private static function confereForma(string $limpo, string $forma, int $tamanho, string $nome): ?FormularioVeredito
    {
        if (!preg_match($forma, $limpo) || strlen($limpo) > $tamanho) {
            return FormularioVeredito::invalida("{$nome} inválido");
        }

        return strlen($limpo) < $tamanho ? FormularioVeredito::incompleta("{$nome} incompleto") : null;
    }

    private static function confereCpf(string $limpo): ?FormularioVeredito
    {
        return self::confereForma($limpo, '/^\d*$/', self::TAMANHO_CPF, 'CPF')
            ?? ((new Cpf())->validate($limpo) ? null : FormularioVeredito::invalida('CPF inválido'));
    }

    private static function confereCnpj(string $limpo): ?FormularioVeredito
    {
        return self::confereForma($limpo, '/^[A-Z0-9]{0,12}$|^[A-Z0-9]{12}\d{1,2}$/', self::TAMANHO_CNPJ, 'CNPJ')
            ?? (self::isCnpjVerificado($limpo) ? null : FormularioVeredito::invalida('CNPJ inválido'));
    }

    private static function confereDocumento(string $limpo): ?FormularioVeredito
    {
        if (preg_match('/^\d{11}$/', $limpo)) {
            return self::confereCpf($limpo);
        }

        $veredito = self::confereCnpj($limpo);

        return $veredito?->isInvalida === false ? FormularioVeredito::incompleta('CPF ou CNPJ incompleto') : $veredito;
    }

    private static function isCnpjVerificado(string $cnpj): bool
    {
        if (preg_match('/^0+$/', $cnpj)) {
            return false;
        }

        $base = substr($cnpj, 0, 12);
        $primeiro = self::digitoDoCnpj($base);

        return $cnpj === $base . $primeiro . self::digitoDoCnpj($base . $primeiro);
    }

    /** Letra vale o código ASCII menos 48, como na regra do CNPJ alfanumérico. */
    private static function digitoDoCnpj(string $trecho): int
    {
        $pesos = array_slice(self::PESOS_CNPJ, -strlen($trecho));
        $valores = array_map(fn(string $caractere) => ord($caractere) - 48, str_split($trecho));
        $resto = array_sum(array_map(fn(int $valor, int $peso) => $valor * $peso, $valores, $pesos)) % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    }
}
