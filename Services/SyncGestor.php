<?php

namespace AldirBlanc\Services;

use MapasCulturais\App;

class SyncGestor
{
    public function __construct()
    {
        $this->app = App::i();
    }

    public function setDocument(string $document): self
    {
        $this->document = $document;
        return $this;
    }
}
