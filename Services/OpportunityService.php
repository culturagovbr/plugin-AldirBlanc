<?php

namespace AldirBlanc\Services;

use AldirBlanc\Entities\FederativeEntity;
use AldirBlanc\Enum\MultiselectField;
use AldirBlanc\Enum\OpportunityStatus;
use AldirBlanc\Enum\SpecialOption;
use AldirBlanc\Enum\TipoProponenteEnum;
use MapasCulturais\App;
use MapasCulturais\Entity;
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

    /**
     * Carrega uma oportunidade por ID com metadados, arquivos, subsite e parent (primeira fase)
     * já hidratados em uma única query, para uso na integração (job/API).
     */
    public function findOpportunityWithIntegrationData(int $id): ?Opportunity
    {
        $app = App::i();
        $dql = "
            SELECT o, om, s, p, pm, opf
            FROM MapasCulturais\Entities\Opportunity o
            LEFT JOIN o.__metadata om
            LEFT JOIN o.subsite s
            LEFT JOIN o.parent p
            LEFT JOIN p.__metadata pm
            LEFT JOIN o.__files opf
            WHERE o.id = :id
        ";
        $query = $app->em->createQuery($dql)->setParameter('id', $id);
        $opportunity = $query->getOneOrNullResult();
        return $opportunity instanceof Opportunity ? $opportunity : null;
    }

    /**
     * Lê o valor bruto de um metadado da coleção __metadata da entidade (sem depender de definição registrada).
     * Usado quando getMetadata() retorna null por falta de tema/registro no worker.
     */
    public function getRawMetadataValue(Entity $entity, string $key): mixed
    {
        try {
            $ref = new \ReflectionProperty($entity, '__metadata');
            $ref->setAccessible(true);
            $collection = $ref->getValue($entity);
        } catch (\ReflectionException) {
            return null;
        }
        if (!is_iterable($collection)) {
            return null;
        }
        foreach ($collection as $meta) {
            if (!is_object($meta) || !property_exists($meta, 'key')) {
                continue;
            }
            if ($meta->key === $key) {
                $value = method_exists($meta, 'getValue') ? $meta->getValue() : ($meta->value ?? null);
                if (is_string($value) && (str_starts_with(trim($value), '{') || str_starts_with(trim($value), '['))) {
                    $decoded = json_decode($value, true);
                    return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
                }
                return $value;
            }
        }
        return null;
    }

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

		// Metadados com fallback na coleção __metadata (quando getter retorna null por falta de definição registrada)
		$recursosOutrasFontesVal = $opportunity->recursosOutrasFontes ?? $this->getRawMetadataValue($opportunity, 'recursosOutrasFontes');
		$formasInscricaoVal = $opportunity->formasInscricaoEdital ?? $this->getRawMetadataValue($opportunity, 'formasInscricaoEdital');
		$segmentoVal = $opportunity->segmento ?? $this->getRawMetadataValue($opportunity, 'segmento');
		$segmentoOutrosVal = $opportunity->segmentoOutros ?? $this->getRawMetadataValue($opportunity, 'segmentoOutros');
		$etapaVal = $opportunity->etapa ?? $this->getRawMetadataValue($opportunity, 'etapa');
		$etapaOutrosVal = $opportunity->etapaOutros ?? $this->getRawMetadataValue($opportunity, 'etapaOutros');
		$pautaVal = $opportunity->pauta ?? $this->getRawMetadataValue($opportunity, 'pauta');
		$pautaOutrosVal = $opportunity->pautaOutros ?? $this->getRawMetadataValue($opportunity, 'pautaOutros');
		$territorioVal = $opportunity->territorio ?? $this->getRawMetadataValue($opportunity, 'territorio');
		$outrasModalidadesVal = $opportunity->outrasModalidadesAcoesAfirmativas ?? $this->getRawMetadataValue($opportunity, 'outrasModalidadesAcoesAfirmativas');

		$firstPhase = $opportunity->firstPhase ?? $opportunity;
		$reservaVagasCotasVal = $firstPhase->reservaVagasCotas ?? $this->getRawMetadataValue($firstPhase, 'reservaVagasCotas');

		$recursosOutrasFontes = $this->mapRecursosOutrasFontes($recursosOutrasFontesVal);
		$tiposFormasInscricao = $this->mapTiposFormasInscricao($formasInscricaoVal);
		$reservaVagasCotas = $this->mapReservaVagasCotas($reservaVagasCotasVal);
		$outrasModalidadesAcoesAfirmativas = $this->mapOutrasModalidadesAcoesAfirmativas($outrasModalidadesVal);

		$tipoDeEditalVal = $opportunity->tipoDeEdital ?? $this->getRawMetadataValue($opportunity, 'tipoDeEdital');
		$vacanciesVal = $opportunity->vacancies ?? $this->getRawMetadataValue($opportunity, 'vacancies');
		$totalResourceVal = $opportunity->totalResource ?? $this->getRawMetadataValue($opportunity, 'totalResource');

		$tiposProponentesVal = $opportunity->registrationProponentTypes ?? $this->getRawMetadataValue($opportunity, 'registrationProponentTypes');
		$tiposProponentes = $this->mapTiposProponentes($tiposProponentesVal);

		$enteFederado = $this->getEnteFederadoByOpportunity($opportunity);

        return [
            'id' => $opportunity->id,
            'numero_e_titulo_edital' => $opportunity->name ?? null,
            'forma_de_execucao' => $tipoDeEditalVal,
            'status' => $this->getOpportunityStatus($opportunity),
            'data_publicacao_edital' => $this->normalizeDateValue($opportunity->publishTimestamp ?? null),
            'detalhamento_objeto' => $opportunity->longDescription ?: ($opportunity->shortDescription ?? null),
            'numero_previsto_vagas' => $vacanciesVal,
            'valor_total_edital' => $this->normalizeDecimalValue($totalResourceVal),
            'data_inicial_prazo_inscricao' => $this->normalizeDateValue($opportunity->registrationFrom ?? null),
            'data_final_prazo_inscricao' => $this->normalizeDateValue($opportunity->registrationTo ?? null),
            'tipos_proponentes' => $tiposProponentes,
            'segmentos_artistico_culturais' => $this->mapMultiselectToString(MultiselectField::SEGMENTO, $segmentoVal),
            'segmento_artistico_cultural_especificar' => $this->normalizeString($segmentoOutrosVal),
            'etapas_fazer_cultural' => $this->mapMultiselectToString(MultiselectField::ETAPA, $etapaVal),
            'etapa_fazer_cultural_especificar' => $this->normalizeString($etapaOutrosVal),
            'pautas_especificas' => $this->mapMultiselectToString(MultiselectField::PAUTA, $pautaVal),
            'pauta_especifica_especificar' => $this->normalizeString($pautaOutrosVal),
            'categorias_edital' => $opportunity->registrationRanges ?? null,
            'recursos_territorios_prioritarios' => $this->mapMultiselectToString(MultiselectField::TERRITORIO, $territorioVal),
            'links_da_pagina_pnab' => $links,
            'pdf_edital' => $urlPdf,
            'recursos_outras_fontes' => $recursosOutrasFontes,
            'tipos_formas_inscricao' => $tiposFormasInscricao,
            'reserva_vagas_cotas' => $reservaVagasCotas,
            'outras_modalidades_acoes_afirmativas' => $outrasModalidadesAcoesAfirmativas,
            'ente_federado' => $enteFederado,
        ];
    }

    /**
     * Obtém o ente federado (quem criou a oportunidade) a partir do metadado federativeEntityId.
     * Retorna array com name e document (CNPJ) ou null se não houver federativeEntityId, se o ente não existir ou se document estiver vazio.
     *
     * @return array{name: string, document: string}|null
     */
    protected function getEnteFederadoByOpportunity($opportunity): ?array
    {
        $federativeEntityId = $opportunity->getMetadata('federativeEntityId') ?? $this->getRawMetadataValue($opportunity, 'federativeEntityId');
        if ($federativeEntityId === null || $federativeEntityId === '') {
            return null;
        }
        $id = (int) $federativeEntityId;
        if ($id <= 0) {
            return null;
        }
        $app = App::i();
        $ente = $app->em->getRepository(FederativeEntity::class)->find($id);
        if (!$ente instanceof FederativeEntity || ($ente->document ?? '') === '') {
            return null;
        }
        $name = $this->normalizeString($ente->name ?? '') ?? '';
        $document = $this->normalizeString($ente->document) ?? '';
        if ($document === '') {
            return null;
        }
        return [
            'name' => $name,
            'document' => $document,
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
     * @param MultiselectField $field
     * @param mixed $value array de chaves ou null
     * @return array<int, string>|null
     */
    protected function mapMultiselectKeysToLabels(MultiselectField $field, $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $keys = is_array($value) ? $value : [];
        if (count($keys) === 0) {
            return null;
        }

        $options = $this->getOpportunityMetadataOptions($field->value);
        $notApplicableLabel = i::__($field->notApplicableLabel());

        // Único valor é "não se direciona" → enviar só a label
        if (count($keys) === 1 && $keys[0] === SpecialOption::NOT_APPLICABLE->value) {
            return [$notApplicableLabel];
        }

        // Segmento: "Todas as opções" → expandir para todas as labels (exceto chaves especiais)
        if ($field === MultiselectField::SEGMENTO && in_array(SpecialOption::ALL_OPTIONS->value, $keys, true)) {
            $labels = [];
            foreach ($options as $k => $label) {
                if ($k !== SpecialOption::NOT_APPLICABLE->value && $k !== SpecialOption::ALL_OPTIONS->value) {
                    $labels[] = $label;
                }
            }
            return array_values($labels);
        }

        // Mapear cada chave para label
        $labels = [];
        foreach ($keys as $key) {
            if ($key === SpecialOption::NOT_APPLICABLE->value) {
                $labels[] = $notApplicableLabel;
            } elseif ($key === SpecialOption::ALL_OPTIONS->value) {
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
     * Converte o resultado de mapMultiselectKeysToLabels em string (ou null) para envio à API externa.
     * Ex.: ["Artes Visuais", "Artesanato"] → "Artes Visuais, Artesanato".
     */
    protected function mapMultiselectToString(MultiselectField $field, $value): ?string
    {
        $labels = $this->mapMultiselectKeysToLabels($field, $value);
        if ($labels === null || $labels === []) {
            return null;
        }
        return implode(', ', $labels);
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
     * Mapeia registrationProponentTypes (labels do Mapas) para os valores do enum da API Oportunidade Cult.
     * Ex.: "Pessoa Física" → "pessoa_fisica", "Pessoa Jurídica" → "pessoa_juridica".
     *
     * @param mixed $raw array de labels (ex.: ["Pessoa Física", "Coletivo"]) ou null
     * @return array<int, string>|null
     */
    protected function mapTiposProponentes($raw): ?array
    {
        if ($raw === null || !is_array($raw)) {
            return null;
        }
        $result = [];
        foreach ($raw as $label) {
            $value = TipoProponenteEnum::fromLabel(is_string($label) ? $label : (string) $label);
            if ($value !== null) {
                $result[] = $value;
            }
        }
        return count($result) > 0 ? array_values($result) : null;
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
        $enum = OpportunityStatus::tryFrom($status);
        if ($enum === null) {
            return [
                'id' => $status,
                'label' => null,
            ];
        }

        return $enum->toPayload(i::__($enum->label()));
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