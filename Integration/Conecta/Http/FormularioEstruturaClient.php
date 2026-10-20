<?php

namespace AldirBlanc\Integration\Conecta\Http;

use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Http\Clients\AbstractClient;
use AldirBlanc\Http\Transport\Transport;

class FormularioEstruturaClient extends AbstractClient
{
    protected const PROVIDER = 'conecta';

    protected const FIXTURE = 'conecta/formulario-estrutura.php';

    private const MODULO_RESULTADO = 'RESULTADO_EDITAL';

    private const SEM_FORMULARIO_PUBLICADO = 404;

    protected string $document;

    public function __construct(int $idTipoAcao, ?Transport $transport = null)
    {
        $this->document = '';

        // A API exige a barra final antes da query: sem ela responde 307, que o transporte não segue.
        $endpoint = rtrim($this->requiredEndpoint('formularioEstruturaEndpoint'), '/') . '/';
        $this->endpoint = $endpoint . '?' . http_build_query([
            'modulo' => self::MODULO_RESULTADO,
            'id_tipo_acao' => $idTipoAcao,
        ]);

        parent::__construct($transport);
    }

    /** Estrutura do formulário de resultado, ou null quando o tipo de ação não tem formulário publicado. */
    public function fetch(): ?array
    {
        try {
            return (array) $this->get();
        } catch (IntegrationError $e) {
            if ($e->httpStatus() === self::SEM_FORMULARIO_PUBLICADO) {
                return null;
            }

            throw $e;
        }
    }
}
