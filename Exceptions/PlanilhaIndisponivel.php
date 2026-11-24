<?php

namespace AldirBlanc\Exceptions;

/** O edital exige a planilha de contemplados, e ela não pode ser enviada. */
final class PlanilhaIndisponivel extends IntegrationError
{
    public function __construct(string $motivo)
    {
        parent::__construct($motivo, self::KIND_CONTRACT);
    }
}
