<?php

namespace AldirBlanc\Integration;

interface FetchesParSituation
{
    /**
     * Situação de cada cadastro do PAR do ente; lista vazia quando ele não tem PAR.
     * @return list<array{ano: ?int, situacao: ?string, excluido: bool}>
     */
    public function fetchParSituation(string $cnpj): array;
}
