<?php

namespace AldirBlanc\Enum;

/** Catálogo de tipos de resultado do PAR (GET /editais/resultado/tipos), confirmado em runtime. */
enum ResultadoTipo: int
{
    case Parcial = 1;
    case Final = 2;
    case FinalRetificado = 3;
}
