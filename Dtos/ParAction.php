<?php

namespace AldirBlanc\Dtos;

class ParAction
{
    public function __construct(
        public string $value,
        public string $label,
        public array $raw = [],
    ) {
    }

    public static function fromArray(array $data): self
    {
        $label = (string) ($data['nome_acao'] ?? '');

        return new self(
            value: $label,
            label: $label,
            raw: $data,
        );
    }

    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label,
            'raw' => $this->raw,
        ];
    }
}
