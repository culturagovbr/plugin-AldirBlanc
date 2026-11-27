<?php

namespace AldirBlanc\Integration\Conecta\Http;

use AldirBlanc\Dtos\ParEditalId;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Http\Clients\AbstractClient;
use AldirBlanc\Http\Transport\Transport;

class ResultadoPublicadoClient extends AbstractClient
{
    protected const PROVIDER = 'conecta';

    protected const FIXTURE = 'conecta/resultado-publicado.php';

    private const PARAMETER_DEFAULT = '{id_edital}';

    /** Outro 404 diz que o edital não existe, e não que ele ainda não publicou. */
    private const SEM_PUBLICADO = 'Resultado publicado não encontrado';

    protected string $document;

    public function __construct(ParEditalId $edital, ?Transport $transport = null)
    {
        $this->document = (string) $edital->id;
        $this->endpoint = $this->requiredEndpoint('resultadoPublicadoEndpoint');

        parent::__construct($transport);

        $this->parameter = self::PARAMETER_DEFAULT;
    }

    /** Id do resultado publicado, ou null quando o edital não publicou nenhum. */
    public function fetchId(): ?int
    {
        try {
            $publicado = (array) $this->get();
        } catch (IntegrationError $e) {
            if ($this->isSemPublicado($e)) {
                return null;
            }

            throw $e;
        }

        if (!is_int($publicado['id_par_edital_resultado'] ?? null)) {
            throw IntegrationError::contract('o resultado publicado veio sem id');
        }

        return $publicado['id_par_edital_resultado'];
    }

    private function isSemPublicado(IntegrationError $erro): bool
    {
        return $erro->httpStatus() === 404
            && (json_decode((string) $erro->rawBody(), true)['detail'] ?? null) === self::SEM_PUBLICADO;
    }
}
