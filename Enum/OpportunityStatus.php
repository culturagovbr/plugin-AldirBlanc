<?php

namespace AldirBlanc\Enum;

/**
 * Status da oportunidade para o payload de integração.
 * Centraliza id e label (chave de tradução) usados na API.
 */
enum OpportunityStatus: int
{
    case ENABLED = 1;
    case DRAFT = 0;
    case PHASE = -1;
    case ARCHIVED = -2;
    case DISABLED = -9;
    case TRASH = -10;
    case APPEAL_PHASE = -20;

    /**
     * Retorna a chave de tradução do status (para uso com i::__()).
     */
    public function label(): string
    {
        return match ($this) {
            self::ENABLED => 'Ativado',
            self::DRAFT => 'Rascunho',
            self::PHASE => 'Fase',
            self::ARCHIVED => 'Arquivado',
            self::DISABLED => 'Desabilitado',
            self::TRASH => 'Lixeira',
            self::APPEAL_PHASE => 'Fase de recurso',
        };
    }

    /**
     * Retorna o payload no formato esperado pela API: ['id' => int, 'label' => string].
     * O label deve ser passado já traduzido (ex.: via i::__($enum->label())).
     */
    public function toPayload(string $translatedLabel): array
    {
        return [
            'id' => $this->value,
            'label' => $translatedLabel,
        ];
    }
}
