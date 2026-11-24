<?php

namespace AldirBlanc\Services;

use MapasCulturais\i;

/** Linha da planilha de contemplados, conferida pelo que cada coluna do modelo declara. */
class LinhasBeneficiariosService
{
    public const FONTE_FICHA = 'ficha';
    public const FONTE_GESTOR = 'gestor';

    private const OBRIGATORIO = 'obrigatorio';
    private const OBRIGATORIEDADES = [self::OBRIGATORIO, 'condicional', 'opcional', 'nao_informado'];

    private const DOCUMENTOS = ['cpf', 'cnpj'];
    private const MONETARIO = 'monetario';
    private const TIPOS = [...self::DOCUMENTOS, self::MONETARIO, 'cep', 'lista', 'texto', 'a_definir'];

    /** @param list<array> $colunas do modelo de planilha do edital */
    public function __construct(private readonly array $colunas)
    {
    }

    /**
     * Célula a célula, o que o gestor digitou vence a fonte do de/para.
     *
     * @return array<string, array{valor: mixed, fonte: string}> valor final e de onde ele veio, por coluna
     */
    public function mesclar(array $daFonte, array $override): array
    {
        $linha = [];

        foreach ($this->colunas as $coluna) {
            $chave = $coluna['coluna'];
            // Célula que o gestor apagou fica apagada: a fonte não volta a preencher o que ele tirou.
            $isDoGestor = array_key_exists($chave, $override);

            $linha[$chave] = [
                'valor' => $this->valor($coluna['tipo'], $isDoGestor ? $override[$chave] : $daFonte[$chave] ?? null),
                'fonte' => $isDoGestor ? self::FONTE_GESTOR : self::FONTE_FICHA,
            ];
        }

        return $linha;
    }

    /** @return array<string, string> motivo por coluna que ainda impede o envio; vazio significa pronta */
    public function pendencias(array $linhaMesclada): array
    {
        $pendencias = [];

        foreach ($this->colunas as $coluna) {
            $motivo = $this->pendencia($coluna, $linhaMesclada[$coluna['coluna']]['valor'] ?? null);

            if ($motivo !== null) {
                $pendencias[$coluna['coluna']] = $motivo;
            }
        }

        return $pendencias;
    }

    /** @return array<string, string> a linha como o contrato a espera: valor cru, sem as colunas vazias */
    public function paraOEnvio(array $linhaMesclada): array
    {
        return array_filter(array_map(fn(array $celula) => $celula['valor'], $linhaMesclada), is_string(...));
    }

    private function pendencia(array $coluna, mixed $valor): ?string
    {
        if (!in_array($coluna['tipo'], self::TIPOS, true) || !in_array($coluna['obrigatoriedade'], self::OBRIGATORIEDADES, true)) {
            return i::__('O CultBR declara esta coluna de uma forma que o sistema ainda não reconhece.');
        }

        if ($valor === null) {
            return $coluna['obrigatoriedade'] === self::OBRIGATORIO ? i::__('Preenchimento obrigatório.') : null;
        }

        if (!is_string($valor)) {
            return i::__('A resposta da ficha traz mais de um valor. Informe um só.');
        }

        return match ($coluna['tipo']) {
            'cpf', 'cnpj', 'cep' => FormularioMascara::confere($coluna['tipo'], $valor)?->motivo,
            self::MONETARIO => preg_match('/^\d+\.\d{2}$/', $valor) ? null : i::__('O valor não é um número.'),
            'lista' => in_array($valor, $coluna['opcoes'] ?? [], true) ? null : i::__('O valor não é uma das opções do CultBR.'),
            default => null,
        };
    }

    /** Vazio vira null, e o que não é um valor só segue como veio, para pender. */
    private function valor(string $tipo, mixed $bruto): mixed
    {
        if (is_array($bruto) || is_object($bruto)) {
            return (array) $bruto === [] ? null : $bruto;
        }

        $texto = is_bool($bruto) ? '' : trim((string) $bruto);

        if ($texto === '') {
            return null;
        }

        if (in_array($tipo, self::DOCUMENTOS, true)) {
            return FormularioMascara::semPontuacao($texto);
        }

        return $tipo === self::MONETARIO ? $this->monetario($bruto) ?? $texto : $texto;
    }

    private function monetario(mixed $bruto): ?string
    {
        if (is_int($bruto) || is_float($bruto)) {
            return number_format($bruto, 2, '.', '');
        }

        $texto = trim(str_replace('R$', '', $bruto));

        // "15.000" é quinze mil, e is_numeric o leria como quinze.
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$|^\d+,\d{1,2}$/', $texto)) {
            $texto = strtr($texto, ['.' => '', ',' => '.']);
        }

        return preg_match('/^\d+(\.\d{1,2})?$/', $texto) ? number_format((float) $texto, 2, '.', '') : null;
    }
}
