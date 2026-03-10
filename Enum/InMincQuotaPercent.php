<?php

namespace AldirBlanc\Enum;

/**
 * Percentuais de cotas previstos na IN-MinC-10/2023.
 *
 * Usamos enum baseado em string para evitar conflitos de valores
 * (o PHP não permite dois cases com o mesmo valor).
 * O método percent() retorna o percentual numérico correspondente.
 */
enum InMincQuotaPercent: string
{
    case BLACK = 'black';
    case INDIGENOUS = 'indigenous';
    case PCD = 'pcd';

    /**
     * Percentual mínimo de PCD no cenário excepcional do § 4º
     * (cálculo sobre o total de vagas do edital).
     */
    case PCD_EXCEPTIONAL = 'pcd_exceptional';

    /**
     * Retorna o percentual numérico associado ao case.
     *
     * @return float
     */
    public function percent(): float
    {
        return match ($this) {
            self::BLACK => 25.0,
            self::INDIGENOUS => 10.0,
            self::PCD => 5.0,
            self::PCD_EXCEPTIONAL => 10.0,
        };
    }
}

