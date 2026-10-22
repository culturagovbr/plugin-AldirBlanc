<?php

namespace AldirBlanc\Services;

use MapasCulturais\i;

/**
 * Linhas da planilha acao_cultural_realizada: uma por inscrição contemplada, montada de três fontes —
 * o que o sistema calcula, o que o de/para traz da ficha e o que o gestor digitou na grade.
 */
class LinhasBeneficiariosService
{
    public const FONTE_AUTOMATICA = 'automatica';
    public const FONTE_FICHA = 'ficha';
    public const FONTE_GESTOR = 'gestor';

    public const COLUNAS = [
        'tipo_agente' => ['fonte' => self::FONTE_AUTOMATICA, 'tipo' => 'texto', 'editavel' => false],
        'identificador_edital' => ['fonte' => self::FONTE_AUTOMATICA, 'tipo' => 'texto', 'editavel' => false],
        'cpf_responsavel_execucao' => ['fonte' => self::FONTE_AUTOMATICA, 'tipo' => 'texto', 'editavel' => false],
        'cnpj_responsavel_execucao' => ['fonte' => self::FONTE_AUTOMATICA, 'tipo' => 'texto', 'editavel' => false],
        'valor_recebido_pnab' => ['fonte' => self::FONTE_AUTOMATICA, 'tipo' => 'moeda', 'editavel' => true],
        'data_recebimento_recurso' => ['fonte' => self::FONTE_GESTOR, 'tipo' => 'data', 'editavel' => true],
        'data_inicio_execucao' => ['fonte' => self::FONTE_GESTOR, 'tipo' => 'data', 'editavel' => true],
        'data_termino_execucao' => ['fonte' => self::FONTE_GESTOR, 'tipo' => 'data', 'editavel' => true],
        'recebeu_recursos_outras_fontes' => ['fonte' => self::FONTE_FICHA, 'tipo' => 'lista', 'editavel' => true],
        'valor_recursos_outras_fontes' => ['fonte' => self::FONTE_FICHA, 'tipo' => 'moeda', 'editavel' => true],
        'qtd_pessoas_remuneradas_exec' => ['fonte' => self::FONTE_FICHA, 'tipo' => 'numero', 'editavel' => true],
        'cpfs_remunerados' => ['fonte' => self::FONTE_GESTOR, 'tipo' => 'lista', 'editavel' => true],
        'formato_execucao' => ['fonte' => self::FONTE_FICHA, 'tipo' => 'texto', 'editavel' => true],
        'cep_local_execucao' => ['fonte' => self::FONTE_FICHA, 'tipo' => 'texto', 'editavel' => true],
    ];

    /** Sem estes o validador do CultBr recusa a planilha inteira, com 422 e nada salvo. */
    public const OBRIGATORIAS = [
        'identificador_edital',
        'valor_recebido_pnab',
        'data_recebimento_recurso',
    ];

    /** CPF e CNPJ satisfazem a mesma exigência, então a dupla pende como um item só. */
    public const PENDENCIA_RESPONSAVEL = 'responsavel_execucao';

    /** Colunas da grade com rótulo e tipo, na ordem do modelo de planilha. */
    public function colunas(): array
    {
        $labels = [
            'tipo_agente' => i::__('Tipo de agente'),
            'identificador_edital' => i::__('Identificador do edital'),
            'cpf_responsavel_execucao' => i::__('CPF do responsável'),
            'cnpj_responsavel_execucao' => i::__('CNPJ do responsável'),
            'valor_recebido_pnab' => i::__('Valor recebido (PNAB)'),
            'data_recebimento_recurso' => i::__('Data de recebimento do recurso'),
            'data_inicio_execucao' => i::__('Início da execução'),
            'data_termino_execucao' => i::__('Término da execução'),
            'recebeu_recursos_outras_fontes' => i::__('Recebeu recursos de outras fontes?'),
            'valor_recursos_outras_fontes' => i::__('Valor de outras fontes'),
            'qtd_pessoas_remuneradas_exec' => i::__('Pessoas remuneradas'),
            'cpfs_remunerados' => i::__('CPFs remunerados'),
            'formato_execucao' => i::__('Formato de execução'),
            'cep_local_execucao' => i::__('CEP do local de execução'),
        ];

        $colunas = [];
        foreach (self::COLUNAS as $chave => $def) {
            $colunas[] = [
                'chave' => $chave,
                'label' => $labels[$chave] ?? $chave,
                'tipo' => $def['tipo'],
                'editavel' => $def['editavel'],
                'obrigatoria' => in_array($chave, self::OBRIGATORIAS, true),
            ];
        }

        return $colunas;
    }

    /**
     * Mescla as três fontes de uma linha: o que o gestor digitou vence a ficha, que vence o calculado.
     *
     * @return array<string, array{valor: mixed, fonte: string}> valor final e de onde ele veio, por coluna
     */
    public function mesclar(array $automatico, array $daFicha, array $override): array
    {
        $linha = [];

        foreach (self::COLUNAS as $chave => $def) {
            // Célula que o gestor apagou fica apagada: a ficha não volta a preencher o que ele tirou.
            if (array_key_exists($chave, $override) && !$this->preenchido($override[$chave])) {
                $linha[$chave] = ['valor' => null, 'fonte' => self::FONTE_GESTOR];
                continue;
            }

            foreach ([self::FONTE_GESTOR => $override, self::FONTE_FICHA => $daFicha, self::FONTE_AUTOMATICA => $automatico] as $fonte => $valores) {
                if ($this->preenchido($valores[$chave] ?? null)) {
                    $linha[$chave] = ['valor' => $valores[$chave], 'fonte' => $fonte];
                    continue 2;
                }
            }

            $linha[$chave] = ['valor' => null, 'fonte' => $def['fonte']];
        }

        return $linha;
    }

    /** Colunas obrigatórias que a linha ainda não tem; vazio significa pronta para o envio. */
    public function pendencias(array $linhaMesclada): array
    {
        $pendentes = [];
        foreach (self::OBRIGATORIAS as $chave) {
            if (!$this->preenchido($linhaMesclada[$chave]['valor'] ?? null)) {
                $pendentes[] = $chave;
            }
        }

        if (!$this->temResponsavel($linhaMesclada)) {
            $pendentes[] = self::PENDENCIA_RESPONSAVEL;
        }

        if ($this->quantidadeRemuneradosDeclarada($linhaMesclada) && !$this->preenchido($linhaMesclada['cpfs_remunerados']['valor'] ?? null)) {
            $pendentes[] = 'cpfs_remunerados';
        }

        if ($this->preenchido($linhaMesclada['recebeu_recursos_outras_fontes']['valor'] ?? null)
            && !$this->preenchido($linhaMesclada['valor_recursos_outras_fontes']['valor'] ?? null)) {
            $pendentes[] = 'valor_recursos_outras_fontes';
        }

        return $pendentes;
    }

    /** Só o CPF ou só o CNPJ basta ao validador, então a dupla conta como uma exigência. */
    public function temResponsavel(array $linhaMesclada): bool
    {
        return $this->preenchido($linhaMesclada['cpf_responsavel_execucao']['valor'] ?? null)
            || $this->preenchido($linhaMesclada['cnpj_responsavel_execucao']['valor'] ?? null);
    }

    private function quantidadeRemuneradosDeclarada(array $linhaMesclada): bool
    {
        return (int) ($linhaMesclada['qtd_pessoas_remuneradas_exec']['valor'] ?? 0) > 0;
    }

    private function preenchido(mixed $valor): bool
    {
        if (is_array($valor)) {
            return $valor !== [];
        }

        return $valor !== null && trim((string) $valor) !== '';
    }
}
