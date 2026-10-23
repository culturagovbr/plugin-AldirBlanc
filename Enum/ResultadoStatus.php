<?php

namespace AldirBlanc\Enum;

/** Catálogo de status de resultado do PAR (GET /editais/resultado/status); publicado não permite edição nem exclusão. */
enum ResultadoStatus: int
{
    case EmRascunho = 1;
    case EmAnalise = 2;
    case Publicado = 3;
    case AguardandoExclusao = 4;
}
