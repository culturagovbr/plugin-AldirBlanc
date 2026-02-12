<?php

namespace AldirBlanc\Services;

use MapasCulturais\Entities\Opportunity;
use MapasCulturais\i;

class OpportunityService
{
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
            'segmentos_artistico_culturais' => $opportunity->segmento ?? null,
            'etapas_fazer_cultural' => $opportunity->etapa ?? null,
            'pautas_especificas' => $opportunity->pauta ?? null,
            'categorias_edital' => $opportunity->registrationRanges ?? null,
            'recursos_territorios_prioritarios' => $opportunity->territorio ?? null,
            'links_da_pagina_pnab' => $links,
            'pdf_edital' => $urlPdf,
            'recursos_outras_fontes' => null,
            'tipos_recursos_outras_fontes' => null,
            'tipos_formas_inscricao' => null
        ];
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