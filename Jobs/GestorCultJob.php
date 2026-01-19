<?php

namespace AldirBlanc\Jobs;

use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Http\Clients\EnteClient;
use AldirBlanc\Http\Clients\GestorClient;

class GestorCultJob
{
    private GestorDocument $gestorDocument;

    public function __construct(GestorDocument $gestorDocument) {
        $this->gestorDocument = $gestorDocument;
    }

    public function sync(): void
    {
        $gestor = (new GestorClient($this->gestorDocument))->get();

        echo "<pre>";
        echo "Gestor: ";
        var_dump($gestor);
        echo "</pre>";
        die();
    }
}
