<?php

namespace AldirBlanc\Enum;

/**
 * Campos multiselect da oportunidade (Pnab) usados no mapeamento para a API.
 * Cada campo tem uma label específica para a opção "Edital não se direciona a...".
 */
enum MultiselectField: string
{
    case SEGMENTO = 'segmento';
    case ETAPA = 'etapa';
    case PAUTA = 'pauta';
    case TERRITORIO = 'territorio';

    /**
     * Retorna a chave de tradução da opção "não se direciona" (para uso com i::__()).
     */
    public function notApplicableLabel(): string
    {
        return match ($this) {
            self::SEGMENTO => 'Edital não se direciona a segmentos específicos',
            self::ETAPA => 'Edital não se direciona a etapa específica',
            self::PAUTA => 'Edital não se direciona a pautas específicas',
            self::TERRITORIO => 'Edital não se direciona a territórios específicos',
        };
    }
}
