<?php

namespace AldirBlanc\Integration\Conecta\Http;

use AldirBlanc\Dtos\ParEditalId;
use AldirBlanc\Http\Clients\AbstractClient;
use AldirBlanc\Http\Transport\Transport;

class OportunidadesListaClient extends AbstractClient
{
    protected const PROVIDER = 'conecta';

    protected const FIXTURE = 'conecta/oportunidades-lista.php';

    private const POR_PAGINA = 10;

    protected string $document = '';

    public function __construct(private readonly ParEditalId $edital, ?Transport $transport = null)
    {
        $this->endpoint = $this->requiredEndpoint('listarOportunidadesEndpoint') . '?' . http_build_query([
            'page' => 1,
            'per_page' => self::POR_PAGINA,
            'id_oportunidades' => $edital->id,
        ]);

        parent::__construct($transport);
    }

    /** `permite_resultado` do edital, ou null quando a lista não o traz. */
    public function fetchPermiteResultado(): ?bool
    {
        foreach (((array) $this->get())['items'] ?? [] as $item) {
            if ((int) ($item['id_oportunidade'] ?? 0) === $this->edital->id) {
                $permite = $item['permite_resultado'] ?? null;

                return is_bool($permite) ? $permite : null;
            }
        }

        return null;
    }
}
