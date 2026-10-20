<?php

namespace AldirBlanc\Services;

/** Valida respostas do formulário de resultado contra a estrutura publicada pelo PAR. */
final class FormularioRespostasValidator
{
    /** Questões sem resposta do gestor: anexos ficam de fora e a visualização do edital é informativa. */
    private const TIPOS_SEM_RESPOSTA = ['anexo', 'visualização simplificada edital'];

    /** A data de publicação é calculada pelo sistema, nunca preenchida — a tag a identifica em todo formulário. */
    private const TAG_DATA_PUBLICACAO = 'data_publicacao';

    private const TABELA_MIN_LINHAS_PADRAO = 1;

    /** @var array<int, array> questões editáveis, por id_questao */
    private array $questoes = [];

    public function __construct(array $estrutura)
    {
        foreach ($estrutura['secoes'] ?? [] as $secao) {
            foreach ($secao['etapas'] ?? [] as $etapa) {
                foreach ($etapa['questoes'] ?? [] as $questao) {
                    if ($this->isEditavel($questao)) {
                        $this->questoes[(int) $questao['id_questao']] = $questao;
                    }
                }
            }
        }
    }

    private function isEditavel(array $questao): bool
    {
        if (in_array($questao['tipo_questao'] ?? '', self::TIPOS_SEM_RESPOSTA, true)) {
            return false;
        }

        if (!empty($questao['somente_leitura'])) {
            return false;
        }

        return !str_starts_with((string) ($questao['tag'] ?? ''), self::TAG_DATA_PUBLICACAO);
    }

    /** @return array<int, string> erros por id_questao; vazio quando todas as respostas são válidas */
    public function validate(array $respostas): array
    {
        $erros = [];

        foreach ($respostas as $idQuestao => $valor) {
            $questao = $this->questoes[(int) $idQuestao] ?? null;

            if ($questao === null) {
                $erros[(int) $idQuestao] = 'questão desconhecida ou não editável';
                continue;
            }

            $erro = $valor === null ? null : $this->validateValor($questao, $valor);

            if ($erro !== null) {
                $erros[(int) $idQuestao] = $erro;
            }
        }

        return $erros;
    }

    /** @return list<int> ids das questões obrigatórias ainda sem resposta válida de completude */
    public function missingRequired(array $respostas): array
    {
        $faltantes = [];

        foreach ($this->questoes as $id => $questao) {
            if (empty($questao['requerida'])) {
                continue;
            }

            if ($this->isIncompleta($questao, $respostas[$id] ?? null)) {
                $faltantes[] = $id;
            }
        }

        return $faltantes;
    }

    private function isIncompleta(array $questao, mixed $valor): bool
    {
        if ($this->isVazio($valor)) {
            return true;
        }

        if (($questao['tipo_questao'] ?? '') !== 'Tabela Dinâmica') {
            return false;
        }

        $minimo = (int) ($questao['config']['minRows'] ?? self::TABELA_MIN_LINHAS_PADRAO);

        return is_array($valor) && count($valor) < $minimo;
    }

    private function validateValor(array $questao, mixed $valor): ?string
    {
        return match ($questao['tipo_questao']) {
            'número', 'moeda' => $this->validateNumero($questao, $valor),
            'data' => $this->validateData($valor),
            'texto', 'textarea' => is_string($valor) ? null : 'o valor deve ser texto',
            'booleano' => is_bool($valor) ? null : 'o valor deve ser verdadeiro ou falso',
            'múltipla escolha' => $this->validateEscolha($questao, $valor),
            'Tabela Dinâmica' => $this->validateTabela($questao, $valor),
            // Tipo que o PAR vier a criar: aceito sem validação local, o servidor deles decide.
            default => null,
        };
    }

    private function validateNumero(array $questao, mixed $valor): ?string
    {
        if (!is_int($valor) && !is_float($valor) && !(is_string($valor) && is_numeric($valor))) {
            return 'o valor deve ser numérico';
        }

        $numero = (float) $valor;

        $minimo = $questao['valor_minimo'] ?? null;
        if ($minimo !== null && $numero < (float) $minimo) {
            return "o valor mínimo é {$minimo}";
        }

        $maximo = $questao['valor_maximo'] ?? null;
        if ($maximo !== null && $numero > (float) $maximo) {
            return "o valor máximo é {$maximo}";
        }

        return null;
    }

    private function validateData(mixed $valor): ?string
    {
        // Round-trip estrito: createFromFormat aceita 2026-02-30 rolando para março — format() desmascara.
        $data = is_string($valor) ? \DateTime::createFromFormat('Y-m-d', $valor) : false;

        if ($data === false || $data->format('Y-m-d') !== $valor) {
            return 'a data deve ser válida e estar no formato AAAA-MM-DD';
        }

        return null;
    }

    private function validateEscolha(array $questao, mixed $valor): ?string
    {
        $valores = is_array($valor) ? $valor : [$valor];

        foreach ($valores as $item) {
            if (!is_string($item)) {
                return 'cada opção deve ser texto';
            }
        }

        if (!empty($questao['permite_inclusao_outros'])) {
            return null;
        }

        $itens = $questao['opcao']['itens'] ?? null;
        if (!is_array($itens) || $itens === []) {
            return null;
        }

        $foraDaLista = array_diff($valores, array_column($itens, 'valor'));

        return $foraDaLista === [] ? null : 'opção fora da lista: ' . implode(', ', $foraDaLista);
    }

    private function validateTabela(array $questao, mixed $valor): ?string
    {
        if (!is_array($valor) || ($valor !== [] && !array_is_list($valor))) {
            return 'o valor deve ser uma lista de linhas';
        }

        $colunas = $questao['config']['columns'] ?? [];
        $obrigatorias = array_values(array_filter(
            $colunas,
            fn($coluna) => !empty($coluna['required']) && empty($coluna['readOnly']),
        ));

        foreach ($valor as $indice => $linha) {
            if (!is_array($linha)) {
                return 'cada linha deve ser um conjunto de colunas';
            }

            foreach ($obrigatorias as $coluna) {
                if ($this->isVazio($linha[$coluna['key']] ?? null)) {
                    $posicao = $indice + 1;

                    return "linha {$posicao}: a coluna \"{$coluna['label']}\" é obrigatória";
                }
            }
        }

        return null;
    }

    private function isVazio(mixed $valor): bool
    {
        return $valor === null || $valor === '' || $valor === [];
    }
}
