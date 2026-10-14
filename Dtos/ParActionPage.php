<?php

namespace AldirBlanc\Dtos;

final class ParActionPage
{
    /**
     * @param list<ParAction> $items
     * @param int $limit o limite efetivamente usado, não o pedido
     * @param ?int $next skip da página seguinte, nulo quando não há
     * @param ?int $previous skip da página anterior, nulo quando não há
     */
    public function __construct(
        public readonly array $items,
        public readonly int $skip,
        public readonly int $limit,
        public readonly int $total,
        public readonly ?int $next = null,
        public readonly ?int $previous = null,
    ) {
    }

    public function hasMore(): bool
    {
        return ($this->skip + count($this->items)) < $this->total;
    }
}
