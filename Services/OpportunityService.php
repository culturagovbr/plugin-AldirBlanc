<?php

namespace AldirBlanc\Services;

use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\i;

class OpportunityService
{
    private const NOT_APPLICABLE_KEY = '__edital_nao_se_direciona__';
    private const ALL_OPTIONS_KEY = '__todas_opcoes__';

    /** Labels "Edital não se direciona a..." por campo (para envio à API em formato label). */
    private const NOT_APPLICABLE_LABELS = [
        'segmento' => 'Edital não se direciona a segmentos específicos',
        'etapa' => 'Edital não se direciona a etapa específica',
        'pauta' => 'Edital não se direciona a pautas específicas',
        'territorio' => 'Edital não se direciona a territórios específicos',
    ];

	public function mapOpportunityToIntegrationPayload($opportunity): array
    {
		// Recupera os links da oportunidade
		$links = $opportunity->getMetaLists('links') ?? null;
		$links = $links ? array_map(function($link) {
			return [
				'url' => $link->value ?? null,
				'label' => $link->title ?? null
			];
		}, $links) : null;

		// Recupera o PDF da oportunidade
		$pdf = $opportunity->getFiles('rules') ?? null;
		$urlPdf = $pdf ? $pdf->url ?? null : null;

		$recursosOutrasFontes = $this->mapRecursosOutrasFontes($opportunity->recursosOutrasFontes ?? null);
		$tiposFormasInscricao = $this->mapTiposFormasInscricao($opportunity->formasInscricaoEdital ?? null);
		$firstPhase = $opportunity->firstPhase ?? $opportunity;
		$reservaVagasCotas = $this->mapReservaVagasCotas($firstPhase->reservaVagasCotas ?? null);
		$outrasModalidadesAcoesAfirmativas = $this->mapOutrasModalidadesAcoesAfirmativas($opportunity->outrasModalidadesAcoesAfirmativas ?? null);

        return [
            'id' => $opportunity->id,
            'numero_e_titulo_edital' => $opportunity->name ?? null,
            'forma_de_execucao' => $opportunity->tipoDeEdital ?? null,
            'status' => $this->getOpportunityStatus($opportunity),
            'data_publicacao_edital' => $this->normalizeDateValue($opportunity->publishTimestamp ?? null),
            'detalhamento_objeto' => $opportunity->longDescription ?: ($opportunity->shortDescription ?? null),
            'numero_previsto_vagas' => $opportunity->vacancies ?? null,
            'valor_total_edital' => $this->normalizeDecimalValue($opportunity->totalResource ?? null),
            'data_inicial_prazo_inscricao' => $this->normalizeDateValue($opportunity->registrationFrom ?? null),
            'data_final_prazo_inscricao' => $this->normalizeDateValue($opportunity->registrationTo ?? null),
            'tipos_proponentes' => $opportunity->registrationProponentTypes ?? null,
            'segmentos_artistico_culturais' => $this->mapMultiselectKeysToLabels('segmento', $opportunity->segmento ?? null),
            'segmento_artistico_cultural_especificar' => $this->normalizeString($opportunity->segmentoOutros ?? null),
            'etapas_fazer_cultural' => $this->mapMultiselectKeysToLabels('etapa', $opportunity->etapa ?? null),
            'etapa_fazer_cultural_especificar' => $this->normalizeString($opportunity->etapaOutros ?? null),
            'pautas_especificas' => $this->mapMultiselectKeysToLabels('pauta', $opportunity->pauta ?? null),
            'pauta_especifica_especificar' => $this->normalizeString($opportunity->pautaOutros ?? null),
            'categorias_edital' => $opportunity->registrationRanges ?? null,
            'recursos_territorios_prioritarios' => $this->mapMultiselectKeysToLabels('territorio', $opportunity->territorio ?? null),
            'links_da_pagina_pnab' => $links,
            'pdf_edital' => $urlPdf,
            'recursos_outras_fontes' => $recursosOutrasFontes,
            'tipos_formas_inscricao' => $tiposFormasInscricao,
            'reserva_vagas_cotas' => $reservaVagasCotas,
            'outras_modalidades_acoes_afirmativas' => $outrasModalidadesAcoesAfirmativas,
        ];
    }

    /**
     * Normaliza outrasModalidadesAcoesAfirmativas (Pnab) para o payload de integração.
     * Estrutura: opcoes (array), outra_legislacao_descricao (string), bonus_agentes, bonus_tematicas, categoria_especifica, edital_especifico (arrays).
     *
     * @param mixed $raw
     * @return array<string, mixed>|null
     */
    protected function mapOutrasModalidadesAcoesAfirmativas($raw): ?array
    {
        if ($raw === null || (!is_array($raw) && !is_object($raw))) {
            return null;
        }
        $data = is_array($raw) ? $raw : (array) $raw;
        $opcoes = $data['opcoes'] ?? null;
        if (!is_array($opcoes)) {
            $opcoes = [];
        }
        $outraLegislacaoDescricao = isset($data['outra_legislacao_descricao'])
            ? $this->normalizeString($data['outra_legislacao_descricao'])
            : '';

        $sublistKeys = ['bonus_agentes', 'bonus_tematicas', 'categoria_especifica', 'edital_especifico'];
        $result = [
            'opcoes' => array_values(array_filter($opcoes, 'is_string')),
            'outra_legislacao_descricao' => $outraLegislacaoDescricao,
        ];
        foreach ($sublistKeys as $key) {
            $sublist = $data[$key] ?? null;
            if (!is_array($sublist)) {
                $result[$key] = [];
            } else {
                $result[$key] = array_values(array_filter($sublist, 'is_string'));
            }
        }
        return $result;
    }

    /**
     * Converte array de chaves (segmento, etapa, pauta, territorio) para array de labels para o payload da API.
     * "Não se direciona" → label correspondente; "Todas as opções" (só segmento) → expande para todas as labels.
     *
     * @param string $field 'segmento'|'etapa'|'pauta'|'territorio'
     * @param mixed $value array de chaves ou null
     * @return array<int, string>|null
     */
    protected function mapMultiselectKeysToLabels(string $field, $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $keys = is_array($value) ? $value : [];
        if (count($keys) === 0) {
            return null;
        }

        $options = $this->getOpportunityMetadataOptions($field);
        $notApplicableLabel = i::__(self::NOT_APPLICABLE_LABELS[$field] ?? self::NOT_APPLICABLE_LABELS['segmento']);

        // Único valor é "não se direciona" → enviar só a label
        if (count($keys) === 1 && $keys[0] === self::NOT_APPLICABLE_KEY) {
            return [$notApplicableLabel];
        }

        // Segmento: "Todas as opções" → expandir para todas as labels (exceto chaves especiais)
        if ($field === 'segmento' && in_array(self::ALL_OPTIONS_KEY, $keys, true)) {
            $labels = [];
            foreach ($options as $k => $label) {
                if ($k !== self::NOT_APPLICABLE_KEY && $k !== self::ALL_OPTIONS_KEY) {
                    $labels[] = $label;
                }
            }
            return array_values($labels);
        }

        // Mapear cada chave para label
        $labels = [];
        foreach ($keys as $key) {
            if ($key === self::NOT_APPLICABLE_KEY) {
                $labels[] = $notApplicableLabel;
            } elseif ($key === self::ALL_OPTIONS_KEY) {
                continue;
            } elseif (isset($options[$key])) {
                $labels[] = $options[$key];
            } else {
                $labels[] = $key;
            }
        }
        return array_values($labels);
    }

    /**
     * Obtém as opções (key => label) do metadado do Opportunity registrado pelo tema Pnab.
     *
     * @param string $key 'segmento'|'etapa'|'pauta'|'territorio'
     * @return array<string, string>
     */
    protected function getOpportunityMetadataOptions(string $key): array
    {
        $app = App::i();
        $meta = $app->getRegisteredMetadataByMetakey($key, Opportunity::class);
        if ($meta === null || !isset($meta->options) || !is_array($meta->options)) {
            return [];
        }
        return $meta->options;
    }

    /**
     * Retorna string trimada ou null se vazia.
     */
    protected function normalizeString(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Normaliza recursosOutrasFontes (Pnab) para o payload de integração.
     * Estrutura: houveUtilizacao, recursosProprios, conveniosParcerias, emendasParlamentares, remanescentesCiclo1, outrasFontes.
     *
     * @param mixed $raw
     * @return array<string, mixed>|null
     */
    protected function mapRecursosOutrasFontes($raw): ?array
    {
        if ($raw === null || (!is_array($raw) && !is_object($raw))) {
            return null;
        }
        $data = is_array($raw) ? $raw : (array) $raw;
        $outrasFontes = $data['outrasFontes'] ?? null;
        $outrasFontesNormalized = null;
        if (is_array($outrasFontes) && count($outrasFontes) > 0) {
            $outrasFontesNormalized = array_map(function ($item) {
                $entry = is_array($item) ? $item : (array) $item;
                return [
                    'nome_fonte' => $entry['nomeFonte'] ?? '',
                    'valor' => isset($entry['valor']) ? $this->normalizeDecimalValue($entry['valor']) : '0.00',
                ];
            }, $outrasFontes);
        }
        return [
            'houve_utilizacao' => $data['houveUtilizacao'] ?? null,
            'recursos_proprios' => isset($data['recursosProprios']) ? $this->normalizeDecimalValue($data['recursosProprios']) : null,
            'convenios_parcerias' => isset($data['conveniosParcerias']) ? $this->normalizeDecimalValue($data['conveniosParcerias']) : null,
            'emendas_parlamentares' => isset($data['emendasParlamentares']) ? $this->normalizeDecimalValue($data['emendasParlamentares']) : null,
            'remanescentes_ciclo_1' => isset($data['remanescentesCiclo1']) ? $this->normalizeDecimalValue($data['remanescentesCiclo1']) : null,
            'outras_fontes' => $outrasFontesNormalized,
        ];
    }

    /**
     * Mapeia formasInscricaoEdital (Pnab) para tipos_formas_inscricao do payload.
     * Retorna array de { tipo, descricao } quando previstasNoEdital === 'sim' e formas preenchido.
     *
     * @param mixed $raw
     * @return array<int, array{tipo: string, descricao: string}>|null
     */
    protected function mapTiposFormasInscricao($raw): ?array
    {
        if ($raw === null || (!is_array($raw) && !is_object($raw))) {
            return null;
        }
        $data = is_array($raw) ? $raw : (array) $raw;
        if (($data['previstasNoEdital'] ?? '') !== 'sim') {
            return null;
        }
        $formas = $data['formas'] ?? null;
        if (!is_array($formas) || count($formas) === 0) {
            return null;
        }
        $result = [];
        foreach ($formas as $f) {
            $item = is_array($f) ? $f : (array) $f;
            $tipo = $item['tipo'] ?? '';
            if ($tipo !== '') {
                $result[] = [
                    'tipo' => $tipo,
                    'descricao' => trim((string) ($item['descricao'] ?? '')),
                ];
            }
        }
        return count($result) > 0 ? $result : null;
    }

    /**
     * Normaliza reservaVagasCotas (primeira fase) para o payload. Quantidade variável de itens.
     * Cada item: label, vagas, valor_destinado, nao_aplicavel (snake_case).
     *
     * @param mixed $raw
     * @return array<int, array{label: string, vagas: int, valor_destinado: string, nao_aplicavel: bool}>|null
     */
    protected function mapReservaVagasCotas($raw): ?array
    {
        if ($raw === null || !is_array($raw)) {
            return null;
        }
        if (count($raw) === 0) {
            return null;
        }
        $result = [];
        foreach ($raw as $cota) {
            $item = is_array($cota) ? $cota : (array) $cota;
            $vagas = isset($item['vagas']) ? (int) $item['vagas'] : 0;
            $valorDestinado = isset($item['valorDestinado']) ? $this->normalizeDecimalValue($item['valorDestinado']) : '0.00';
            $result[] = [
                'label' => trim((string) ($item['label'] ?? '')),
                'vagas' => $vagas,
                'valor_destinado' => $valorDestinado,
                'nao_aplicavel' => (bool) ($item['naoAplicavel'] ?? false),
            ];
        }
        return $result;
    }

    protected function getOpportunityStatus($opportunity): ?array
    {
        $statusValue = $opportunity->status ?? null;
        if ($statusValue === null) {
            return null;
        }

        $status = (int) $statusValue;
        $label = Opportunity::getStatusNameById($status);

        if (!$label) {
            if ($status === Opportunity::STATUS_PHASE) {
                $label = i::__('Fase');
            } elseif ($status === Opportunity::STATUS_APPEAL_PHASE) {
                $label = i::__('Fase de recurso');
            }
        }

        return [
            'id' => $status,
            'label' => $label ?? null
        ];
    }

    protected function normalizeDecimalValue($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(['.', ','], ['', '.'], $value);
        }

        if (!is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    protected function normalizeDateValue($value): ?string
    {
        if (!$value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_array($value) && isset($value['date'])) {
            return $value['date'];
        }

        if (is_object($value) && isset($value->date)) {
            return $value->date;
        }

        if (is_string($value)) {
            return $value;
        }

        return null;
    }
}