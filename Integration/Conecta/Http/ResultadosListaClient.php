<?php

namespace AldirBlanc\Integration\Conecta\Http;

use AldirBlanc\Dtos\ParEditalId;
use AldirBlanc\Enum\ResultadoStatus;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Http\Clients\AbstractClient;
use AldirBlanc\Http\Transport\Transport;

class ResultadosListaClient extends AbstractClient
{
    protected const PROVIDER = 'conecta';

    protected const FIXTURE = 'conecta/resultados-lista.php';

    private const PARAMETER_DEFAULT = '{id_edital}';

    private const LIMITE = 100;

    protected string $document;

    public function __construct(ParEditalId $edital, ?Transport $transport = null)
    {
        $this->document = (string) $edital->id;
        $this->endpoint = $this->requiredEndpoint('criarResultadoEndpoint') . '?' . http_build_query(['limit' => self::LIMITE]);

        parent::__construct($transport);

        $this->parameter = self::PARAMETER_DEFAULT;
    }

    /** @return list<int> ids publicados; lista incompleta ou ilegível não serve para comparar */
    public function fetchIdsPublicados(): array
    {
        $lista = (array) $this->get();
        $resultados = $lista['data'] ?? null;
        $total = $lista['pagination']['total'] ?? null;

        if (!is_array($resultados) || $total !== count($resultados)) {
            throw IntegrationError::contract('a lista de resultados do edital veio incompleta');
        }

        $publicados = array_filter($resultados, $this->isPublicado(...));

        return array_values(array_map($this->id(...), $publicados));
    }

    private function isPublicado(array $resultado): bool
    {
        return ($resultado['id_par_edital_resultado_status'] ?? null) === ResultadoStatus::Publicado->value;
    }

    private function id(array $resultado): int
    {
        if (!is_int($resultado['id_par_edital_resultado'] ?? null)) {
            throw IntegrationError::contract('a lista de resultados do edital traz um resultado sem id');
        }

        return $resultado['id_par_edital_resultado'];
    }
}
