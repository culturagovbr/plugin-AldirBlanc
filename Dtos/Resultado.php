<?php

namespace AldirBlanc\Dtos;

/** Corpo de criação de resultado na API Conecta MinC; id_tipo e id_status são obrigatórios no contrato. */
class Resultado
{
    public function __construct(
        public int $id_tipo,
        public int $id_status,
        public ?string $data_publicacao = null,
        /** @var array<int, array{id_par_questao: int, resposta: array{resposta: mixed}}>|null */
        public ?array $respostas = null,
        /** @var array<int, array{nome: string, caminho: string, descricao?: ?string}>|null */
        public ?array $anexos = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id_tipo: (int) ($data['id_tipo'] ?? 0),
            id_status: (int) ($data['id_status'] ?? 0),
            data_publicacao: isset($data['data_publicacao']) ? (string) $data['data_publicacao'] : null,
            respostas: isset($data['respostas']) && is_array($data['respostas']) ? $data['respostas'] : null,
            anexos: isset($data['anexos']) && is_array($data['anexos']) ? $data['anexos'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id_tipo' => $this->id_tipo,
            'id_status' => $this->id_status,
            'data_publicacao' => $this->data_publicacao,
            'respostas' => $this->respostas,
            'anexos' => $this->anexos,
        ];
    }
}
