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

    /**
     * Só os valores atestados pelo modelo de planilha e pelo payload de referência do CultBr.
     * A lista completa não está documentada, e valor fora dela derruba a planilha inteira.
     */
    public const OPCOES = [
        'recebeu_recursos_outras_fontes' => [
            'Não',
            'Sim - Governo Estadual',
            'Sim - Setor Privado',
        ],
    ];

    /** Sem estes o validador do CultBr recusa a planilha inteira, com 422 e nada salvo. */
    public const OBRIGATORIAS = [
        'identificador_edital',
        'valor_recebido_pnab',
        'data_recebimento_recurso',
    ];

    /** Menos do que a grade cobra: no envio a data de recebimento deixou de ser obrigatória. */
    public const OBRIGATORIAS_NO_ENVIO = [
        'identificador_edital',
        'valor_recebido_pnab',
    ];

    /** Escolha que não convive com as outras da mesma coluna: marcá-la desmarca o resto. */
    public const OPCOES_EXCLUSIVAS = [
        'recebeu_recursos_outras_fontes' => self::RESPOSTA_SEM_OUTRAS_FONTES,
    ];

    public const RESPOSTA_SEM_OUTRAS_FONTES = 'Não';

    /** As datas da linha, na ordem em que os fatos acontecem. */
    private const SEQUENCIA_DAS_DATAS = [
        'data_recebimento_recurso',
        'data_inicio_execucao',
        'data_termino_execucao',
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
                'opcoes' => self::OPCOES[$chave] ?? null,
                'opcaoExclusiva' => self::OPCOES_EXCLUSIVAS[$chave] ?? null,
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
        return array_merge(
            $this->faltando(self::OBRIGATORIAS, $linhaMesclada),
            $this->contradicoes($linhaMesclada),
        );
    }

    /** @return string[] o que impede esta linha de ir no envio; vazio significa pronta */
    public function faltasParaEnvio(array $linhaMesclada): array
    {
        return array_merge(
            $this->faltando(self::OBRIGATORIAS_NO_ENVIO, $linhaMesclada),
            $this->contradicoes($linhaMesclada),
        );
    }

    /** @return string[] */
    private function faltando(array $obrigatorias, array $linhaMesclada): array
    {
        $faltas = [];
        foreach ($obrigatorias as $chave) {
            if (!$this->preenchido($linhaMesclada[$chave]['valor'] ?? null)) {
                $faltas[] = $chave;
            }
        }

        if (!$this->temResponsavel($linhaMesclada)) {
            $faltas[] = self::PENDENCIA_RESPONSAVEL;
        }

        return $faltas;
    }

    /**
     * Linha que se contradiz: cobrada na grade e também no envio, porque o validador recusa a planilha inteira por qualquer uma delas.
     *
     * @return string[]
     */
    private function contradicoes(array $linhaMesclada): array
    {
        $achadas = [];

        if ($this->quantidadeRemuneradosDeclarada($linhaMesclada) && !$this->cpfsBatemComAQuantidade($linhaMesclada)) {
            $achadas[] = 'cpfs_remunerados';
        }

        if ($this->valorDeOutrasFontesNaoBateComAResposta($linhaMesclada)) {
            $achadas[] = 'valor_recursos_outras_fontes';
        }

        return array_merge($achadas, $this->datasForaDaOrdem($linhaMesclada));
    }

    /**
     * A linha como o contrato a espera: valor cru, sem a fonte, e sem as colunas vazias.
     *
     * @return array<string, mixed>
     */
    public function paraOEnvio(array $linhaMesclada): array
    {
        $linha = [];
        foreach (array_keys(self::COLUNAS) as $chave) {
            $valor = $linhaMesclada[$chave]['valor'] ?? null;

            if ($this->preenchido($valor)) {
                $linha[$chave] = $valor;
            }
        }

        return $linha;
    }

    /** Só o CPF ou só o CNPJ basta ao validador, então a dupla conta como uma exigência. */
    public function temResponsavel(array $linhaMesclada): bool
    {
        return $this->preenchido($linhaMesclada['cpf_responsavel_execucao']['valor'] ?? null)
            || $this->preenchido($linhaMesclada['cnpj_responsavel_execucao']['valor'] ?? null);
    }

    private function quantidadeRemuneradosDeclarada(array $linhaMesclada): bool
    {
        return $this->quantidadeRemunerados($linhaMesclada) > 0;
    }

    /**
     * Declarar fonte sem dizer quanto, ou negar e mesmo assim informar valor: as duas são
     * contradição na mesma linha, e o validador recusa a planilha por qualquer uma delas.
     */
    private function valorDeOutrasFontesNaoBateComAResposta(array $linhaMesclada): bool
    {
        $respondeu = $this->preenchido($linhaMesclada['recebeu_recursos_outras_fontes']['valor'] ?? null);
        $temValor = $this->preenchido($linhaMesclada['valor_recursos_outras_fontes']['valor'] ?? null);

        if (!$respondeu) {
            return false;
        }

        return $this->negouOutrasFontes($linhaMesclada) ? $temValor : !$temValor;
    }

    private function negouOutrasFontes(array $linhaMesclada): bool
    {
        $resposta = $linhaMesclada['recebeu_recursos_outras_fontes']['valor'] ?? null;

        return array_values(array_filter(is_array($resposta) ? $resposta : [$resposta])) === [self::RESPOSTA_SEM_OUTRAS_FONTES];
    }

    /**
     * O recurso chega antes de a execução começar, e ela termina depois de começada.
     *
     * @return string[] as colunas cuja data contraria a data válida anterior
     */
    public function datasForaDaOrdem(array $linhaMesclada): array
    {
        $foraDaOrdem = [];
        $ultimaValida = null;

        foreach (self::SEQUENCIA_DAS_DATAS as $chave) {
            $data = $this->dataComparavel($linhaMesclada[$chave]['valor'] ?? null);

            if ($data === null) {
                continue;
            }

            if ($ultimaValida !== null && $data < $ultimaValida) {
                $foraDaOrdem[] = $chave;
                continue;
            }

            $ultimaValida = $data;
        }

        return $foraDaOrdem;
    }

    private function dataComparavel(mixed $valor): ?string
    {
        return preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', (string) $valor, $partes)
            ? $partes[3] . $partes[2] . $partes[1]
            : null;
    }

    /** O validador não aceita lista mais curta nem mais longa que a quantidade declarada. */
    private function cpfsBatemComAQuantidade(array $linhaMesclada): bool
    {
        $cpfs = $linhaMesclada['cpfs_remunerados']['valor'] ?? null;

        return count(is_array($cpfs) ? $cpfs : array_filter([$cpfs])) === $this->quantidadeRemunerados($linhaMesclada);
    }

    private function quantidadeRemunerados(array $linhaMesclada): int
    {
        return (int) ($linhaMesclada['qtd_pessoas_remuneradas_exec']['valor'] ?? 0);
    }

    private function preenchido(mixed $valor): bool
    {
        if (is_array($valor)) {
            return $valor !== [];
        }

        return $valor !== null && trim((string) $valor) !== '';
    }
}
