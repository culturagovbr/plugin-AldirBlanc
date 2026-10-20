<?php

namespace AldirBlanc\Services;

use AldirBlanc\Dtos\OpportunityId;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Integration\FetchesFormularioResultado;
use AldirBlanc\Plugin;
use MapasCulturais\App;

/** Estrutura do formulário de resultado do PAR: resolve o tipo de ação do edital e busca com cache. */
class FormularioResultadoService
{
    private const CACHE_ESTRUTURA = 'aldirblanc:cultbr:formulario_resultado:tipo_acao:';
    private const CACHE_TIPO_ACAO = 'aldirblanc:cultbr:par_tipo_acao:oportunidade:';

    /** Estrutura do formulário do edital, ou null quando ele não tem formulário de resultado no PAR. */
    public function formStructureForOpportunity(int $opportunityId): ?array
    {
        $idTipoAcao = $this->parTipoAcaoForOpportunity($opportunityId);

        return $idTipoAcao === null ? null : $this->formStructureForTipoAcao($idTipoAcao);
    }

    /** Estrutura do formulário do tipo de ação, ou null quando não há formulário publicado (404). */
    public function formStructureForTipoAcao(int $idTipoAcao): ?array
    {
        return $this->cached(
            self::CACHE_ESTRUTURA . $idTipoAcao,
            fn() => $this->provider()->fetchFormularioResultado($idTipoAcao),
        );
    }

    /** Tipo de ação PAR do edital, ou null quando a oportunidade não existe na origem ou vem sem o tipo. */
    public function parTipoAcaoForOpportunity(int $opportunityId): ?int
    {
        return $this->cached(
            self::CACHE_TIPO_ACAO . $opportunityId,
            fn() => $this->provider()->fetchParTipoAcao(new OpportunityId($opportunityId)),
        );
    }

    private function provider(): FetchesFormularioResultado
    {
        $provider = Plugin::getInstance()->integrationProvider();

        if (!$provider instanceof FetchesFormularioResultado) {
            throw IntegrationError::configuration('client.provider', 'o provedor configurado não serve o formulário de resultado');
        }

        return $provider;
    }

    /** O null (sem formulário / não sincronizada) também é cacheado — por isso o embrulho em array. */
    private function cached(string $chave, callable $busca): mixed
    {
        $app = App::i();
        $ttl = (int) (Plugin::getInstance()->config['integration']['cacheTTL'] ?? 0);

        if ($ttl <= 0) {
            return $busca();
        }

        if ($app->cache->contains($chave)) {
            return $app->cache->fetch($chave)['valor'];
        }

        $valor = $busca();
        $app->cache->save($chave, ['valor' => $valor], $ttl);

        return $valor;
    }
}
