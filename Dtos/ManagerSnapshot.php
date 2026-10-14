<?php

namespace AldirBlanc\Dtos;

/**
 * Os dados do gestor e seus entes. Cada campo tem um par `hasX()`/`x()`: campo presente e nulo
 * é a origem dizendo que está vazio; campo ausente é a origem não tendo falado dele.
 */
final class ManagerSnapshot
{
    public const FIELDS = ['name', 'rg', 'cep', 'cellphone', 'number', 'complement'];

    /**
     * @param array<string, ?string> $fields só as chaves que vieram na resposta
     * @param list<FederativeEntitySnapshot> $entities
     */
    public function __construct(
        private readonly array $fields,
        private readonly array $entities = [],
    ) {
        // Sem isto, um nome de campo digitado errado vira hasX() falso em silêncio.
        $desconhecidos = array_diff(array_keys($fields), self::FIELDS);

        if ($desconhecidos !== []) {
            throw new \InvalidArgumentException(
                'Campo fora do contrato do gestor: ' . implode(', ', $desconhecidos),
            );
        }
    }

    public function hasName(): bool
    {
        return array_key_exists('name', $this->fields);
    }

    public function name(): ?string
    {
        return $this->fields['name'] ?? null;
    }

    public function hasRg(): bool
    {
        return array_key_exists('rg', $this->fields);
    }

    public function rg(): ?string
    {
        return $this->fields['rg'] ?? null;
    }

    public function hasCep(): bool
    {
        return array_key_exists('cep', $this->fields);
    }

    public function cep(): ?string
    {
        return $this->fields['cep'] ?? null;
    }

    public function hasCellphone(): bool
    {
        return array_key_exists('cellphone', $this->fields);
    }

    public function cellphone(): ?string
    {
        return $this->fields['cellphone'] ?? null;
    }

    public function hasNumber(): bool
    {
        return array_key_exists('number', $this->fields);
    }

    public function number(): ?string
    {
        return $this->fields['number'] ?? null;
    }

    public function hasComplement(): bool
    {
        return array_key_exists('complement', $this->fields);
    }

    public function complement(): ?string
    {
        return $this->fields['complement'] ?? null;
    }

    /** @return list<FederativeEntitySnapshot> */
    public function entities(): array
    {
        return $this->entities;
    }
}
