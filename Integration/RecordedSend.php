<?php

namespace AldirBlanc\Integration;

use AldirBlanc\Dtos\Opportunity as OpportunityDto;
use AldirBlanc\Dtos\SendOutcome;
use AldirBlanc\Enum\Provider;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Exceptions\SendFailed;

/** Envia guardando o que aconteceu, inclusive na falha — que é quando a tentativa mais importa. */
final class RecordedSend
{
    public static function perform(OpportunitySender $client, OpportunityDto $payload, Provider $provider): SendOutcome
    {
        $registrado = null;
        $client->setExchangeRecorder(function (array $exchange) use (&$registrado) {
            $registrado = $exchange;
        });

        try {
            $client->update($payload);
        } catch (IntegrationError $e) {
            // Sem exchange não há tentativa a registrar: a falha veio antes de a chamada sair.
            if ($registrado === null) {
                throw $e;
            }

            throw new SendFailed(SendOutcomeMapper::fromExchange($registrado, $provider), $e);
        } finally {
            $client->setExchangeRecorder(null);
        }

        if ($registrado === null) {
            throw IntegrationError::contract('envio não registrou o que aconteceu');
        }

        return SendOutcomeMapper::fromExchange($registrado, $provider);
    }
}
