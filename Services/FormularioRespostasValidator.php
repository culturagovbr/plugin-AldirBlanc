<?php

namespace AldirBlanc\Services;

/** Valida respostas do formulário de resultado contra a estrutura publicada pelo PAR. */
final class FormularioRespostasValidator
{
    /** Anexo vai em arquivo próprio e a visualização do edital é informativa. */
    private const TIPOS_SEM_RESPOSTA = ['anexo', 'múltiplos anexos', 'visualização simplificada edital'];

    /** A data de publicação é calculada pelo sistema, nunca preenchida — a tag a identifica em todo formulário. */
    private const TAG_DATA_PUBLICACAO = 'data_publicacao';

    private const FORMATOS_DE_DATA = ['Y-m-d'];
    private const FORMATOS_DE_DATA_COM_HORA = ['Y-m-d', 'Y-m-d\TH:i', 'Y-m-d\TH:i:s'];

    /** @var array<int, array> questões editáveis, por id_questao */
    private array $questoes = [];

    public function __construct(array $estrutura)
    {
        $this->questoes = array_filter((new FormularioEstrutura($estrutura))->questoes(), $this->isEditavel(...));
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

    /** @return array<int, mixed> só as respostas de questões que o gestor preenche */
    public function editaveis(array $respostas): array
    {
        return array_intersect_key($respostas, $this->questoes);
    }

    /** @return array<int, string> erros das inválidas, por id_questao; vazio quando todas podem ser gravadas */
    public function validate(array $respostas): array
    {
        $erros = [];

        foreach ($respostas as $idQuestao => $valor) {
            $questao = $this->questoes[(int) $idQuestao] ?? null;

            if ($questao === null) {
                $erros[(int) $idQuestao] = 'questão desconhecida ou não editável';
                continue;
            }

            $veredito = $this->veredito($questao, $valor);

            if ($veredito?->isInvalida) {
                $erros[(int) $idQuestao] = $veredito->motivo;
            }
        }

        return $erros;
    }

    /** @return array<int, string> o que falta em cada resposta começada, por id_questao */
    public function incompletas(array $respostas): array
    {
        $motivos = [];

        foreach ($this->editaveis($respostas) as $id => $valor) {
            $veredito = $this->veredito($this->questoes[$id], $valor);

            if ($veredito?->isInvalida === false) {
                $motivos[$id] = $veredito->motivo;
            }
        }

        return $motivos;
    }

    /** @return list<int> ids das questões obrigatórias ainda sem resposta completa */
    public function missingRequired(array $respostas): array
    {
        $incompletas = $this->incompletas($respostas);
        $faltantes = [];

        foreach ($this->questoes as $id => $questao) {
            if (empty($questao['requerida'])) {
                continue;
            }

            if ($this->isVazio($respostas[$id] ?? null) || isset($incompletas[$id])) {
                $faltantes[] = $id;
            }
        }

        return $faltantes;
    }

    private function veredito(array $questao, mixed $valor): ?FormularioVeredito
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return match ($questao['tipo_questao']) {
            'número', 'moeda' => $this->confereNumeroDaQuestao($questao, $valor),
            'data' => $this->confereData($questao, $valor),
            'texto', 'textarea' => $this->confereTextoDaQuestao($questao, $valor),
            'booleano' => is_bool($valor) ? null : FormularioVeredito::invalida('o valor deve ser verdadeiro ou falso'),
            'radio' => $this->confereItem($questao, $valor),
            'checkbox', 'múltipla escolha' => $this->confereEscolhas($questao, $valor),
            'tabela', 'Tabela Dinâmica' => $this->confereTabela($questao, $valor),
            default => FormularioVeredito::invalida('tipo de questão não suportado'),
        };
    }

    private function confereNumeroDaQuestao(array $questao, mixed $valor): ?FormularioVeredito
    {
        $config = $questao['config'] ?? [];
        $minimos = array_filter([$questao['valor_minimo'] ?? null, $config['numericoMinimo'] ?? null], is_numeric(...));

        return $this->confereNumero($valor, $minimos === [] ? null : max($minimos), $questao['valor_maximo'] ?? null)
            ?? $this->confereInteiro($valor, !empty($config['numericoSomenteInteiros']));
    }

    private function confereNumero(mixed $valor, mixed $minimo, mixed $maximo): ?FormularioVeredito
    {
        if (!is_int($valor) && !is_float($valor) && !(is_string($valor) && is_numeric($valor))) {
            return FormularioVeredito::invalida('o valor deve ser numérico');
        }

        if ($minimo !== null && (float) $valor < (float) $minimo) {
            return FormularioVeredito::invalida("o valor mínimo é {$minimo}");
        }

        if ($maximo !== null && (float) $valor > (float) $maximo) {
            return FormularioVeredito::invalida("o valor máximo é {$maximo}");
        }

        return null;
    }

    private function confereInteiro(mixed $valor, bool $somenteInteiros): ?FormularioVeredito
    {
        return $somenteInteiros && floor((float) $valor) !== (float) $valor
            ? FormularioVeredito::invalida('o valor deve ser um número inteiro')
            : null;
    }

    private function confereData(array $questao, mixed $valor): ?FormularioVeredito
    {
        $comHora = !empty($questao['config']['comHora']);
        $formatos = $comHora ? self::FORMATOS_DE_DATA_COM_HORA : self::FORMATOS_DE_DATA;

        foreach (is_string($valor) ? $formatos : [] as $formato) {
            $data = \DateTime::createFromFormat($formato, $valor);

            // Round-trip estrito: createFromFormat aceita 2026-02-30 rolando para março — format() desmascara.
            if ($data !== false && $data->format($formato) === $valor) {
                return null;
            }
        }

        return FormularioVeredito::invalida('a data deve ser válida e estar no formato AAAA-MM-DD' . ($comHora ? ' ou AAAA-MM-DDTHH:MM' : ''));
    }

    private function confereTextoDaQuestao(array $questao, mixed $valor): ?FormularioVeredito
    {
        $config = $questao['config'] ?? [];
        $limites = array_filter([$config['limiteCaracteres'] ?? null, $questao['valor_maximo'] ?? null], is_numeric(...));

        return $this->confereTexto($valor, $limites === [] ? null : min($limites), $questao['valor_minimo'] ?? null)
            ?? $this->confereMascaras([$questao['mascara'] ?? null, $config['padraoValidacao'] ?? null], $valor, $questao['nome'] ?? '');
    }

    private function confereTexto(mixed $valor, mixed $limite, mixed $minimo = null): ?FormularioVeredito
    {
        if (!is_string($valor)) {
            return FormularioVeredito::invalida('o valor deve ser texto');
        }

        if ($limite !== null && mb_strlen($valor) > (int) $limite) {
            return FormularioVeredito::invalida('o texto passa de ' . (int) $limite . ' caracteres');
        }

        if ($minimo !== null && $valor !== '' && mb_strlen($valor) < (int) $minimo) {
            return FormularioVeredito::invalida('o texto precisa de pelo menos ' . (int) $minimo . ' caracteres');
        }

        return null;
    }

    private function confereMascaras(array $mascaras, string $texto, string $nome): ?FormularioVeredito
    {
        if ($texto === '') {
            return null;
        }

        $vereditos = array_map(
            fn(string $mascara) => FormularioMascara::confere($mascara, $texto),
            array_values(array_unique(array_filter($mascaras))),
        );
        $veredito = FormularioVeredito::maisGrave($vereditos);

        return $veredito?->comNome($nome);
    }

    private function confereEscolhas(array $questao, mixed $valor): ?FormularioVeredito
    {
        if (!is_array($valor) || !array_is_list($valor)) {
            return FormularioVeredito::invalida('o valor deve ser uma lista de opções');
        }

        if ($valor === []) {
            return null;
        }

        return FormularioVeredito::maisGrave([
            ...array_map(fn(mixed $escolha) => $this->confereItem($questao, $escolha), $valor),
            $this->confereMarcacoes($questao, array_map(self::idDaEscolha(...), $valor)),
        ]);
    }

    /** A escolha simples é o id do item; a que tem campo complementar é {id, complemento}. */
    private function confereItem(array $questao, mixed $escolha): ?FormularioVeredito
    {
        $id = self::idDaEscolha($escolha);
        $item = $this->item($questao, $id);

        if ($item === null) {
            return FormularioVeredito::invalida('opção fora da lista' . ($id === null ? '' : ": {$id}"));
        }

        if (!isset($item['campoComplementar'])) {
            return is_string($escolha) ? null : FormularioVeredito::invalida("a opção \"{$item['label']}\" não aceita complemento");
        }

        return $this->confereComplemento($item, is_array($escolha) ? ($escolha['complemento'] ?? '') : '');
    }

    private function confereComplemento(array $item, mixed $complemento): ?FormularioVeredito
    {
        $campo = $item['campoComplementar'];

        if (is_string($complemento) && trim($complemento) === '') {
            return FormularioVeredito::incompleta("falta o complemento da opção \"{$item['label']}\"");
        }

        return $this->confereTexto($complemento, $campo['limiteCaracteres'] ?? null)
            ?? $this->confereMascaras([$campo['padraoValidacao'] ?? null], $complemento, $campo['nome'] ?? $item['label']);
    }

    /** @param list<?string> $ids */
    private function confereMarcacoes(array $questao, array $ids): ?FormularioVeredito
    {
        $limite = $questao['opcao']['limiteMarcacoes'] ?? null;
        $minimo = $questao['opcao']['limiteMinimoMarcacoes'] ?? null;
        $exclusivas = array_filter($ids, fn(?string $id) => !empty($this->item($questao, $id)['exclusiva']));

        return match (true) {
            count(array_unique($ids)) < count($ids) => FormularioVeredito::invalida('a mesma opção foi marcada mais de uma vez'),
            $exclusivas !== [] && count($ids) > 1 => FormularioVeredito::invalida('a opção exclusiva não pode ser marcada com outras'),
            $limite !== null && count($ids) > (int) $limite => FormularioVeredito::invalida("o limite é de {$limite} marcações"),
            $minimo !== null && count($ids) < (int) $minimo => FormularioVeredito::incompleta("marque pelo menos {$minimo} opções"),
            default => null,
        };
    }

    private static function idDaEscolha(mixed $escolha): ?string
    {
        $id = is_array($escolha) ? ($escolha['id'] ?? null) : $escolha;

        return is_string($id) ? $id : null;
    }

    private function item(array $questao, ?string $id): ?array
    {
        foreach (FormularioEstrutura::itens($questao) as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        return null;
    }

    private function confereTabela(array $questao, mixed $valor): ?FormularioVeredito
    {
        if (!is_array($valor) || !array_is_list($valor) || $valor !== array_filter($valor, is_array(...))) {
            return FormularioVeredito::invalida('o valor deve ser uma lista de linhas');
        }

        $tabela = FormularioTabela::daQuestao($questao);

        if ($tabela->maxLinhas !== null && count($valor) > $tabela->maxLinhas) {
            return FormularioVeredito::invalida("a tabela aceita no máximo {$tabela->maxLinhas} linhas");
        }

        $vereditos = [];

        foreach ($valor as $indice => $linha) {
            $vereditos[] = $this->confereLinha($tabela, $linha)?->comNome('linha ' . ($indice + 1));
        }

        if (count($valor) < $tabela->minLinhas) {
            $vereditos[] = FormularioVeredito::incompleta("preencha pelo menos {$tabela->minLinhas} linha(s)");
        }

        return FormularioVeredito::maisGrave($vereditos);
    }

    private function confereLinha(FormularioTabela $tabela, array $linha): ?FormularioVeredito
    {
        $editaveis = array_filter($tabela->colunas, fn(array $coluna) => !$coluna['somenteLeitura']);

        return FormularioVeredito::maisGrave(array_map(
            fn(array $coluna) => $this->confereCelula($coluna, $linha[$coluna['id']] ?? null)?->comNome($coluna['nome']),
            $editaveis,
        ));
    }

    private function confereCelula(array $coluna, mixed $valor): ?FormularioVeredito
    {
        if ($this->isVazio($valor)) {
            return $coluna['obrigatoria'] ? FormularioVeredito::incompleta('preenchimento obrigatório') : null;
        }

        return match ($coluna['tipo']) {
            'text' => $this->confereTexto($valor, $coluna['limiteCaracteres']) ?? $this->confereMascaras([$coluna['mascara']], $valor, ''),
            'number', 'currency' => $this->confereNumero($valor, $coluna['minimo'], $coluna['maximo']),
            'dropdown', 'escolha_unica' => in_array($valor, $coluna['opcoes'], true) ? null : FormularioVeredito::invalida('opção fora da lista'),
            default => FormularioVeredito::invalida("tipo de coluna não suportado: {$coluna['tipo']}"),
        };
    }

    private function isVazio(mixed $valor): bool
    {
        return $valor === null || $valor === '' || $valor === [];
    }
}
