<?php

namespace AldirBlanc\Enum;

/**
 * Chaves especiais usadas nos multiselects (segmento, etapa, pauta, território).
 */
enum SpecialOption: string
{
    case NOT_APPLICABLE = '__edital_nao_se_direciona__';
    case ALL_OPTIONS = '__todas_opcoes__';
}
