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

    private const ROTA_INEXISTENTE = 'Not Found';

    protected string $document;

    public function __construct(int $idTipoAcao, ?Transport $transport = null)
    {
        $this->document = '';

        // A rota canônica é SEM barra final: com ela a API responde 307 (e o Location rebaixa para http).
        $endpoint = rtrim($this->requiredEndpoint('formularioEstruturaEndpoint'), '/');
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
            if ($this->isSemFormularioPublicado($e)) {
                return null;
            }

            throw $e;
        }
    }

    /** Endereço errado também responde 404, e some com a aba de todo edital. */
    private function isSemFormularioPublicado(IntegrationError $erro): bool
    {
        if ($erro->httpStatus() !== self::SEM_FORMULARIO_PUBLICADO) {
            return false;
        }

        $detalhe = json_decode((string) $erro->rawBody(), true)['detail'] ?? null;

        return is_string($detalhe) && trim($detalhe) !== '' && $detalhe !== self::ROTA_INEXISTENTE;
    }
}
