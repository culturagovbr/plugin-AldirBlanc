<?php

namespace AldirBlanc\Exceptions;

use AldirBlanc\Dtos\SendOutcome;

/** Envio que falhou, carregando o que a API respondeu para a tentativa ser registrada. */
final class SendFailed extends IntegrationError
{
    public function __construct(private readonly SendOutcome $outcome, IntegrationError $cause)
    {
        parent::__construct(
            $cause->getMessage(),
            $cause->kind(),
            $cause->httpStatus(),
            $cause->rawBody(),
            $cause->getCode(),
            $cause,
            $cause->details(),
        );
    }

    /** O desfecho do envio que falhou. */
    public function outcome(): SendOutcome
    {
        return $this->outcome;
    }
}
