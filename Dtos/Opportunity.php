<?php

namespace AldirBlanc\Dtos;

class Opportunity
{
    public function __construct(
        public int $id,
        public ?string $numero_e_titulo_edital = null,
        public ?string $forma_de_execucao = null,
        public ?array $status = null,
        public ?string $data_publicacao_edital = null,
        public ?string $detalhamento_objeto = null,
        public ?int $numero_previsto_vagas = null,
        public ?string $valor_total_edital = null,
        public ?string $data_inicial_prazo_inscricao = null,
        public ?string $data_final_prazo_inscricao = null,
        public mixed $tipos_proponentes = null,
        public mixed $segmentos_artistico_culturais = null,
        public ?string $segmento_artistico_cultural_especificar = null,
        public mixed $etapas_fazer_cultural = null,
        public ?string $etapa_fazer_cultural_especificar = null,
        public mixed $pautas_especificas = null,
        public ?string $pauta_especifica_especificar = null,
        public mixed $categorias_edital = null,
        public mixed $recursos_territorios_prioritarios = null,
        /** @var array<int, array{url: ?string, label: ?string}>|null */
        public ?array $links_da_pagina_pnab = null,
        public ?string $pdf_edital = null,
        public mixed $recursos_outras_fontes = null,
        public mixed $tipos_formas_inscricao = null,
        /** @var array<int, array{label: string, vagas: int, valor_destinado: string, nao_aplicavel: bool}>|null */
        public ?array $reserva_vagas_cotas = null,
        /** @var array{opcoes: array, outra_legislacao_descricao: string, bonus_agentes: array, bonus_tematicas: array, categoria_especifica: array, edital_especifico: array}|null */
        public ?array $outras_modalidades_acoes_afirmativas = null,
        /** @var array{name: string, document: string}|null */
        public ?array $ente_federado = null,
    ) {
    }

    /**
     * Cria o DTO a partir do array recebido da API do Mapas.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) ($data['id'] ?? 0),
            numero_e_titulo_edital: isset($data['numero_e_titulo_edital']) ? (string) $data['numero_e_titulo_edital'] : null,
            forma_de_execucao: isset($data['forma_de_execucao']) ? (string) $data['forma_de_execucao'] : null,
            status: isset($data['status']) && is_array($data['status']) ? $data['status'] : null,
            data_publicacao_edital: isset($data['data_publicacao_edital']) ? (string) $data['data_publicacao_edital'] : null,
            detalhamento_objeto: isset($data['detalhamento_objeto']) ? (string) $data['detalhamento_objeto'] : null,
            numero_previsto_vagas: isset($data['numero_previsto_vagas']) ? (int) $data['numero_previsto_vagas'] : null,
            valor_total_edital: isset($data['valor_total_edital']) ? (string) $data['valor_total_edital'] : null,
            data_inicial_prazo_inscricao: isset($data['data_inicial_prazo_inscricao']) ? (string) $data['data_inicial_prazo_inscricao'] : null,
            data_final_prazo_inscricao: isset($data['data_final_prazo_inscricao']) ? (string) $data['data_final_prazo_inscricao'] : null,
            tipos_proponentes: $data['tipos_proponentes'] ?? null,
            segmentos_artistico_culturais: $data['segmentos_artistico_culturais'] ?? null,
            segmento_artistico_cultural_especificar: isset($data['segmento_artistico_cultural_especificar']) ? (string) $data['segmento_artistico_cultural_especificar'] : null,
            etapas_fazer_cultural: $data['etapas_fazer_cultural'] ?? null,
            etapa_fazer_cultural_especificar: isset($data['etapa_fazer_cultural_especificar']) ? (string) $data['etapa_fazer_cultural_especificar'] : null,
            pautas_especificas: $data['pautas_especificas'] ?? null,
            pauta_especifica_especificar: isset($data['pauta_especifica_especificar']) ? (string) $data['pauta_especifica_especificar'] : null,
            categorias_edital: $data['categorias_edital'] ?? null,
            recursos_territorios_prioritarios: $data['recursos_territorios_prioritarios'] ?? null,
            links_da_pagina_pnab: isset($data['links_da_pagina_pnab']) && is_array($data['links_da_pagina_pnab']) ? $data['links_da_pagina_pnab'] : null,
            pdf_edital: isset($data['pdf_edital']) ? (string) $data['pdf_edital'] : null,
            recursos_outras_fontes: $data['recursos_outras_fontes'] ?? null,
            tipos_formas_inscricao: $data['tipos_formas_inscricao'] ?? null,
            reserva_vagas_cotas: isset($data['reserva_vagas_cotas']) && is_array($data['reserva_vagas_cotas']) ? $data['reserva_vagas_cotas'] : null,
            outras_modalidades_acoes_afirmativas: isset($data['outras_modalidades_acoes_afirmativas']) && is_array($data['outras_modalidades_acoes_afirmativas']) ? $data['outras_modalidades_acoes_afirmativas'] : null,
            ente_federado: isset($data['ente_federado']) && is_array($data['ente_federado']) ? [
                'name' => (string) ($data['ente_federado']['name'] ?? ''),
                'document' => (string) ($data['ente_federado']['document'] ?? ''),
            ] : null,
        );
    }

    /**
     * Converte o DTO de volta para o formato de array do payload de integração.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'numero_e_titulo_edital' => $this->numero_e_titulo_edital,
            'forma_de_execucao' => $this->forma_de_execucao,
            'status' => $this->status,
            'data_publicacao_edital' => $this->data_publicacao_edital,
            'detalhamento_objeto' => $this->detalhamento_objeto,
            'numero_previsto_vagas' => $this->numero_previsto_vagas,
            'valor_total_edital' => $this->valor_total_edital,
            'data_inicial_prazo_inscricao' => $this->data_inicial_prazo_inscricao,
            'data_final_prazo_inscricao' => $this->data_final_prazo_inscricao,
            'tipos_proponentes' => $this->tipos_proponentes,
            'segmentos_artistico_culturais' => $this->segmentos_artistico_culturais,
            'segmento_artistico_cultural_especificar' => $this->segmento_artistico_cultural_especificar,
            'etapas_fazer_cultural' => $this->etapas_fazer_cultural,
            'etapa_fazer_cultural_especificar' => $this->etapa_fazer_cultural_especificar,
            'pautas_especificas' => $this->pautas_especificas,
            'pauta_especifica_especificar' => $this->pauta_especifica_especificar,
            'categorias_edital' => $this->categorias_edital,
            'recursos_territorios_prioritarios' => $this->recursos_territorios_prioritarios,
            'links_da_pagina_pnab' => $this->links_da_pagina_pnab,
            'pdf_edital' => $this->pdf_edital,
            'recursos_outras_fontes' => $this->recursos_outras_fontes,
            'tipos_formas_inscricao' => $this->tipos_formas_inscricao,
            'reserva_vagas_cotas' => $this->reserva_vagas_cotas,
            'outras_modalidades_acoes_afirmativas' => $this->outras_modalidades_acoes_afirmativas,
            'ente_federado' => $this->ente_federado,
        ];
    }
}
