<?php

namespace AldirBlanc\Services;

use AldirBlanc\Dtos\ParEditalId;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Integration\FetchesModeloPlanilha;
use AldirBlanc\Plugin;
use MapasCulturais\App;

/** Modelo de planilha que o CultBR define para o resultado de cada edital. */
class ModeloPlanilhaService
{
    private const CACHE = 'aldirblanc:cultbr:modelo_planilha:edital:';

    /** Segundos até tentar de novo a leitura que falhou. */
    private const CACHE_FALHA_TTL = 60;

    /**
     * Modelo do edital, guardado pelo tempo do formulário, ou null quando a origem não responde.
     * @return array{possui: bool, motivo: ?string, modelo: ?string, nomeModelo: ?string}|null
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
     * @return array{possui: bool, motivo: ?string, modelo: ?string, nomeModelo: ?string}
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
        ];

        if ($this->ttl() > 0) {
            App::i()->cache->save(self::CACHE . $idParEdital, ['valor' => $modelo], $this->ttl());
        }

        return $modelo;
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
