<?php

namespace AldirBlanc\Dtos;

class OpportunityId
{
    public function __construct(public int $id) {
        $this->id = $id;
    }
}