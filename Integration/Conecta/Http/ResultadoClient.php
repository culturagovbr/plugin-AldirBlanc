<?php

namespace AldirBlanc\Integration\Conecta\Http;

use AldirBlanc\Dtos\ParEditalId;
use AldirBlanc\Dtos\Resultado;
use AldirBlanc\Http\Clients\AbstractClient;
use AldirBlanc\Http\Transport\Transport;
use AldirBlanc\Integration\ResultadoSender;
use AldirBlanc\Services\ResultadoRecusas;

class ResultadoClient extends AbstractClient implements ResultadoSender
{
    protected const PROVIDER = 'conecta';

    private const PARAMETER_DEFAULT = '{id_edital}';

    /** Documento reconhecido pelo conteúdo: a coluna nem sempre declara máscara. */
    private const DOCUMENTO = '/^(\d{3}\.?\d{3}\.?\d{3}-?\d{2}|[A-Z0-9]{2}\.?[A-Z0-9]{3}\.?[A-Z0-9]{3}\/?[A-Z0-9]{4}-?\d{2})$/i';

    protected string $document;

    public function __construct(?Transport $transport = null)
    {
        $this->endpoint = '';
        $this->document = '';

        parent::__construct($transport);

        $this->parameter = self::PARAMETER_DEFAULT;
    }

    public function create(ParEditalId $edital, Resultado $payload): mixed
    {
        $this->endpoint = $this->requiredEndpoint('criarResultadoEndpoint');
        $this->document = (string) $edital->id;

        return $this->post($payload->toArray());
    }

    protected function recordedPayload(array $data): array
    {
        if (isset($data['linhas'])) {
            $data['linhas'] = ['total' => count($data['linhas']), 'hash' => hash('sha256', (string) json_encode($data['linhas']))];
        }

        if (isset($data['respostas'])) {
            $data['respostas'] = array_map($this->semDocumentos(...), $data['respostas']);
        }

        return $data;
    }

    /** Só células de tabela trazem documento de pessoa. */
    private function semDocumentos(array $resposta): array
    {
        $linhas = $resposta['resposta']['resposta'] ?? null;

        foreach (is_array($linhas) ? $linhas : [] as $indice => $linha) {
            if (is_array($linha)) {
                $resposta['resposta']['resposta'][$indice] = array_map($this->semDocumento(...), $linha);
            }
        }

        return $resposta;
    }

    private function semDocumento(mixed $celula): mixed
    {
        return is_string($celula) && preg_match(self::DOCUMENTO, trim($celula))
            ? preg_replace('/[A-Z0-9]/i', '*', $celula)
            : $celula;
    }

    protected function recordedResponse(string $response): string
    {
        $decodificada = json_decode($response, true);

        if (!is_array($decodificada) || !is_array($decodificada['inconsistencias'] ?? null)) {
            return $response;
        }

        $decodificada['inconsistencias'] = array_map(
            ResultadoRecusas::semValorRecebido(...),
            array_filter($decodificada['inconsistencias'], is_array(...)),
        );

        return (string) json_encode($decodificada, JSON_UNESCAPED_UNICODE);
    }

    protected function bodyForLog(string $body): string
    {
        return '';
    }
}
