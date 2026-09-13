<?php

namespace AldirBlanc\Dtos;

final class ParActionPage
{
    /**
     * @param list<ParAction> $items
     * @param int $limit o limite efetivamente usado, não o pedido
     */
    public function __construct(
        public readonly array $items,
        public readonly int $skip,
        public readonly int $limit,
        public readonly int $total,
    ) {
    }

    public function hasMore(): bool
    {
        return ($this->skip + count($this->items)) < $this->total;
    }
}
