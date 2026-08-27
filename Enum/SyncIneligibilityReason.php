<?php

namespace AldirBlanc\Enum;

/**
 * Por que uma oportunidade não é enviada ao CultBR.
 */
enum SyncIneligibilityReason: string
{
    case NO_FEDERATIVE_ENTITY = 'no_federative_entity';
    case NO_SUBSITE = 'no_subsite';
    case SUBSITE_NOT_CONFIGURED = 'subsite_not_configured';
    case OTHER_SUBSITE = 'other_subsite';
    case IS_PHASE = 'is_phase';
    case HAS_PARENT = 'has_parent';
    case INCOMPLETE_PAR = 'incomplete_par';

    /**
     * Retorna a chave de tradução do motivo (para uso com i::__()).
     */
    public function label(): string
    {
        return match ($this) {
            self::NO_FEDERATIVE_ENTITY => 'Sem ente federado associado',
            self::NO_SUBSITE => 'Sem subsite associado',
            self::SUBSITE_NOT_CONFIGURED => 'Subsite da integração não configurado',
            self::OTHER_SUBSITE => 'De outro subsite',
            self::IS_PHASE => 'É uma fase',
            self::HAS_PARENT => 'É uma oportunidade complementar',
            self::INCOMPLETE_PAR => 'Dados do PAR incompletos',
        };
    }
}
