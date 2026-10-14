<?php

namespace AldirBlanc\Dtos;

final class FederativeEntitySnapshot
{
    /** @param array $exercices a árvore do PAR: exercício → meta → ação → atividade */
    public function __construct(
        public readonly string $name,
        public readonly string $document,
        public readonly array $exercices = [],
    ) {
    }

    public function hasParData(): bool
    {
        return $this->exercices !== [];
    }
}
