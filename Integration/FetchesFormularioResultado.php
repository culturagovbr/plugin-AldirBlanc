<?php

namespace AldirBlanc\Integration;

use AldirBlanc\Dtos\OpportunityId;

/** Segregada no molde das demais capacidades: quem não serve o formulário de resultado não a implementa. */
interface FetchesFormularioResultado
{
    /** Estrutura do formulário de resultado do tipo de ação, ou null quando não há formulário publicado. */
    public function fetchFormularioResultado(int $idTipoAcao): ?array;

    /** Tipo de ação PAR da oportunidade sincronizada, ou null quando ela não existe na origem. */
    public function fetchParTipoAcao(OpportunityId $opportunity): ?int;
}
