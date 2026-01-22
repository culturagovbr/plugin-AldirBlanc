<?php

namespace AldirBlanc\Dtos;

class GestorDocument
{
    public function __construct(public string $document) {
        $this->document = $document;
    }
}
