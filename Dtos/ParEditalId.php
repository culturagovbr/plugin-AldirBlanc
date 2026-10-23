<?php

namespace AldirBlanc\Dtos;

/** Id do edital no PAR (CultBR) — não confundir com o id da oportunidade no Mapas. */
class ParEditalId
{
    public function __construct(public readonly int $id)
    {
        if ($id < 1) {
            throw new \InvalidArgumentException("id_par_edital inválido: {$id}");
        }
    }
}
