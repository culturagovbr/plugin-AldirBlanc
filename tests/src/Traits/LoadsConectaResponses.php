<?php

namespace Tests\AldirBlanc\Traits;

/** Respostas reais da Conecta de homologação, usadas como entrada de teste. */
trait LoadsConectaResponses
{
    protected function respostaDaConecta(string $nome): array
    {
        return json_decode($this->corpoDaConecta($nome), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function corpoDaConecta(string $nome): string
    {
        return (string) file_get_contents(dirname(__DIR__) . "/Fixtures/conecta/{$nome}.json");
    }
}
