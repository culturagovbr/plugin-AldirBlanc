<?php

namespace AldirBlanc\Services;

use AldirBlanc\Dtos\ParEditalId;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Integration\FetchesModeloPlanilha;
use AldirBlanc\Plugin;
use MapasCulturais\App;
use MapasCulturais\i;

/** Modelo de planilha que o CultBR define para o resultado de cada edital. */
class ModeloPlanilhaService
{
    private const CACHE = 'aldirblanc:cultbr:modelo_planilha:edital:';

    /** Segundos até tentar de novo a leitura que falhou. */
    private const CACHE_FALHA_TTL = 60;

    /**
     * Situação da planilha na oportunidade: null fora do PAR, `possui` nulo quando a origem não responde.
     * @param bool $isEnvio relê da origem em vez de usar o guardado
     * @return array{possui: ?bool, motivo: ?string, modelo: ?string, nomeModelo: ?string, colunas: list<array>}|null
     */
    public function daOportunidade(int $opportunityId, bool $isEnvio = false): ?array
    {
        $idParEdital = (new ResultadoService())->parEditalId($opportunityId);

        if ($idParEdital === null) {
            return null;
        }

        try {
            $modelo = $isEnvio ? $this->doEnvio($idParEdital) : $this->doEdital($idParEdital);
        } catch (IntegrationError) {
            $modelo = null;
        }

        return $modelo ?? [
            'possui' => null,
            'motivo' => i::__('Não foi possível consultar o modelo de planilha no CultBR. Tente novamente em instantes.'),
            'modelo' => null,
            'nomeModelo' => null,
            'colunas' => [],
        ];
    }

    /**
     * Modelo do edital, guardado pelo tempo do formulário, ou null quando a origem não responde.
     * @return array{possui: bool, motivo: ?string, modelo: ?string, nomeModelo: ?string, colunas: list<array>}|null
     */
    public function doEdital(int $idParEdital): ?array
    {
        $cache = App::i()->cache;
        $chave = self::CACHE . $idParEdital;

        if ($this->ttl() > 0 && $cache->contains($chave)) {
            return $cache->fetch($chave)['valor'];
        }

        try {
            return $this->doEnvio($idParEdital);
        } catch (IntegrationError $e) {
            App::i()->log->warning("[CultBR] Modelo de planilha do edital {$idParEdital} indisponível: " . $e->getMessage());
            $cache->save($chave, ['valor' => null], self::CACHE_FALHA_TTL);

            return null;
        }
    }

    /**
     * Modelo relido da origem, que também regrava o guardado; a falha segue como erro.
     * @return array{possui: bool, motivo: ?string, modelo: ?string, nomeModelo: ?string, colunas: list<array>}
     */
    public function doEnvio(int $idParEdital): array
    {
        $resposta = $this->provider()->fetchModeloPlanilha(new ParEditalId($idParEdital));

        if (!is_bool($resposta['possui_planilha'] ?? null)) {
            throw IntegrationError::contract('o modelo de planilha não diz se o edital possui planilha');
        }

        $modelo = [
            'possui' => $resposta['possui_planilha'],
            'motivo' => $resposta['motivo'] ?? null,
            'modelo' => $resposta['modelo'] ?? null,
            'nomeModelo' => $resposta['nome_modelo'] ?? null,
            'colunas' => array_map($this->coluna(...), $resposta['campos'] ?? []),
        ];

        if ($this->ttl() > 0) {
            App::i()->cache->save(self::CACHE . $idParEdital, ['valor' => $modelo], $this->ttl());
        }

        return $modelo;
    }

    /** Coluna como a origem a declara; o que ela não traz fica nulo. */
    private function coluna(array $campo): array
    {
        return [
            'coluna' => (string) ($campo['coluna'] ?? ''),
            'nome' => (string) ($campo['nome'] ?? $campo['coluna'] ?? ''),
            'tipo' => (string) ($campo['tipo'] ?? ''),
            'obrigatoriedade' => (string) ($campo['obrigatoriedade'] ?? ''),
            'condicao' => $campo['condicao'] ?? null,
            'opcoes' => $campo['opcoes'] ?? null,
            'observacao' => $campo['observacao'] ?? null,
        ];
    }

    private function provider(): FetchesModeloPlanilha
    {
        $provider = Plugin::getInstance()->integrationProvider();

        if (!$provider instanceof FetchesModeloPlanilha) {
            throw IntegrationError::configuration('client.provider', 'o provedor configurado não serve o modelo de planilha');
        }

        return $provider;
    }

    private function ttl(): int
    {
        return (int) (Plugin::getInstance()->config['integration']['formularioCacheTTL'] ?? 0);
    }
}
