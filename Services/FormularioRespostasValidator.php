<?php

namespace AldirBlanc\Services;

/** Valida respostas do formulário de resultado contra a estrutura publicada pelo PAR. */
final class FormularioRespostasValidator
{
    /** Anexo vai em arquivo próprio e a visualização do edital é informativa. */
    private const TIPOS_SEM_RESPOSTA = ['anexo', 'múltiplos anexos', 'visualização simplificada edital'];

    private const TIPO_NAO_SUPORTADO = 'tipo de questão não suportado';

    private const TIPOS_DE_TABELA = ['tabela', 'Tabela Dinâmica'];

    private const TIPOS_COM_RESPOSTA = [
        'número', 'moeda', 'data', 'texto', 'textarea', 'booleano', 'radio', 'checkbox', 'múltipla escolha', ...self::TIPOS_DE_TABELA,
    ];

    private const TIPOS_DE_COLUNA = ['text', 'number', 'currency', 'dropdown', 'escolha_unica'];

    private const FORMATOS_DE_DATA = ['Y-m-d'];
    private const FORMATOS_DE_DATA_COM_HORA = ['Y-m-d', 'Y-m-d\TH:i', 'Y-m-d\TH:i:s'];

    private readonly FormularioRegras $regras;

    /** @var array<int, array> questões editáveis, por id_questao */
    private array $questoes = [];

    /** @var array<int, array> documentos que o gestor anexa, por id_questao */
    private array $documentos = [];

    public function __construct(array $estrutura)
    {
        $leitura = new FormularioEstrutura($estrutura);
        $this->regras = new FormularioRegras($leitura);
        $this->questoes = array_filter($leitura->questoes(), $this->isEditavel(...));
        $this->documentos = array_filter(
            $leitura->questoes(),
            fn(array $questao) => FormularioEstrutura::isDocumento($questao) && empty($questao['somente_leitura']),
        );
    }

    private function isEditavel(array $questao): bool
    {
        if (in_array($questao['tipo_questao'] ?? '', self::TIPOS_SEM_RESPOSTA, true)) {
            return false;
        }

        return empty($questao['somente_leitura']);
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

    /** @return array<int, string> o que falta em cada questão visível para o envio, por id_questao */
    public function pendencias(array $respostas): array
    {
        $motivos = [];

        foreach ($this->visiveis($respostas) as $id => $questao) {
            $motivo = $this->pendencia($questao, $respostas[$id] ?? null);

            if ($motivo !== null) {
                $motivos[$id] = $motivo;
            }
        }

        return $motivos;
    }

    /** @return array<int, mixed> só as respostas completas de questões que o gestor preenche */
    public function enviaveis(array $respostas): array
    {
        return array_filter(
            $this->editaveis($respostas),
            fn(mixed $valor, int $id) => $this->isRespondida($this->questoes[$id], $valor) && $this->veredito($this->questoes[$id], $valor) === null,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** @return array<int, string> o que esta versão do sistema não sabe tratar, por id_questao */
    public function bloqueios(array $respostas): array
    {
        return array_filter(array_map($this->bloqueio(...), $this->visiveis($respostas)));
    }

    /** @return array<int, array> */
    private function visiveis(array $respostas): array
    {
        return array_filter(
            $this->questoes + $this->documentos,
            fn(array $questao) => $this->regras->isVisivel($questao, $respostas),
        );
    }

    private function pendencia(array $questao, mixed $valor): ?string
    {
        $isRequerida = !empty($questao['requerida']);

        if (FormularioEstrutura::isDocumento($questao)) {
            return $isRequerida ? 'envio de documentos ainda não disponível' : null;
        }

        return $this->veredito($questao, $valor)?->motivo
            ?? ($isRequerida && !$this->isRespondida($questao, $valor) ? 'resposta obrigatória' : null);
    }

    /** Tabela sem linha é resposta: o mínimo de linhas, quando há, já foi conferido. */
    private function isRespondida(array $questao, mixed $valor): bool
    {
        return in_array($questao['tipo_questao'], self::TIPOS_DE_TABELA, true) ? is_array($valor) : !$this->isVazio($valor);
    }

    private function bloqueio(array $questao): ?string
    {
        if (!$this->regras->isTodaSuportada($questao)) {
            return 'regra de exibição não suportada';
        }

        if (FormularioEstrutura::isDocumento($questao)) {
            return null;
        }

        if (!in_array($questao['tipo_questao'], self::TIPOS_COM_RESPOSTA, true)) {
            return self::TIPO_NAO_SUPORTADO;
        }

        $colunas = FormularioTabela::daQuestao($questao)->colunas;
        $tipos = array_diff(array_column($colunas, 'tipo'), self::TIPOS_DE_COLUNA);

        if ($tipos !== []) {
            return 'tipo de coluna não suportado: ' . implode(', ', $tipos);
        }

        $mascaras = array_filter(
            [...$this->mascaras($questao), ...array_column($colunas, 'mascara')],
            fn(?string $mascara) => $mascara !== null && !FormularioMascara::isConhecida($mascara),
        );

        return $mascaras === [] ? null : 'máscara não reconhecida: ' . implode(', ', $mascaras);
    }

    /** @return list<?string> */
    private function mascaras(array $questao): array
    {
        $complementos = array_column(FormularioEstrutura::itens($questao), 'campoComplementar');

        return [
            $questao['mascara'] ?? null,
            $questao['config']['padraoValidacao'] ?? null,
            ...array_column($complementos, 'padraoValidacao'),
        ];
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
            default => FormularioVeredito::invalida(self::TIPO_NAO_SUPORTADO),
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
