<?php

namespace AldirBlanc\Services;

use AldirBlanc\Controller;
use AldirBlanc\Dtos\OpportunityId;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Integration\FetchesFormularioResultado;
use AldirBlanc\Plugin;
use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;

/** Estrutura do formulário de resultado do PAR: resolve o tipo de ação do edital e busca com cache. */
class FormularioResultadoService
{
    public const ESTADO_SEM_FORMULARIO = 'sem-formulario';
    public const ESTADO_AGUARDANDO_PUBLICACAO = 'aguardando-publicacao';
    public const ESTADO_PENDENTE = 'pendente';
    public const ESTADO_COMPLETO = 'completo';
    public const ESTADO_ENVIADO = 'enviado';

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

    /** O retrato que a tela do edital consome: estado do ciclo, estrutura e respostas salvas. */
    public function formStateForOpportunity(Opportunity $raiz): array
    {
        $estrutura = $this->formStructureForOpportunity((int) $raiz->id);
        $respostas = $this->savedRespostas((int) $raiz->id);
        $estado = $this->estado($raiz, $estrutura, $respostas);

        return [
            'estado' => $estado,
            'estrutura' => $estrutura,
            'respostas' => $respostas,
            'envioHabilitado' => $estado === self::ESTADO_COMPLETO,
        ];
    }

    private function estado(Opportunity $raiz, ?array $estrutura, array $respostas): string
    {
        if ($estrutura === null) {
            return self::ESTADO_SEM_FORMULARIO;
        }

        if (!$this->isFaseFinalPublicada($raiz)) {
            return self::ESTADO_AGUARDANDO_PUBLICACAO;
        }

        if ((new ResultadoService())->parEditalResultadoId((int) $raiz->id) !== null) {
            return self::ESTADO_ENVIADO;
        }

        $faltantes = (new FormularioRespostasValidator($estrutura))->missingRequired($respostas);

        return $faltantes === [] ? self::ESTADO_COMPLETO : self::ESTADO_PENDENTE;
    }

    /** O travamento do card tem espelho aqui: sem a publicação final, nada se edita nem se salva. */
    public function isFaseFinalPublicada(Opportunity $raiz): bool
    {
        $faseFinal = $raiz->lastPhase;

        return (bool) ($faseFinal && $faseFinal->isLastPhase && $faseFinal->publishedRegistrations);
    }

    /** @return array<int, mixed> respostas salvas do formulário, por id_questao */
    public function savedRespostas(int $raizId): array
    {
        $respostas = $this->savedFormData($raizId)['respostas'] ?? [];

        return is_array($respostas) ? $respostas : [];
    }

    /**
     * Merge parcial sobre as respostas salvas (null limpa a resposta) e grava com a versão do
     * formulário vigente, para detectar estrutura que mudou entre o preenchimento e o envio.
     * @return array<int, mixed> as respostas como ficaram salvas
     */
    public function saveRespostas(int $raizId, array $respostas, array $estrutura): array
    {
        $salvas = $this->savedRespostas($raizId);

        foreach ($respostas as $idQuestao => $valor) {
            if ($valor === null) {
                unset($salvas[(int) $idQuestao]);
                continue;
            }

            $salvas[(int) $idQuestao] = $valor;
        }

        $this->upsertMeta($raizId, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_FORM, json_encode([
            'id_formulario' => $estrutura['id_formulario'] ?? null,
            'id_formulario_versao' => $estrutura['id_formulario_versao'] ?? null,
            'versao' => $estrutura['versao'] ?? null,
            'respostas' => $salvas,
        ], JSON_UNESCAPED_UNICODE));

        return $salvas;
    }

    private function savedFormData(int $raizId): array
    {
        $bruto = App::i()->em->getConnection()->fetchOne(
            'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => $raizId, 'key' => Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_FORM],
        );

        $dados = is_string($bruto) && $bruto !== '' ? json_decode($bruto, true) : null;

        return is_array($dados) ? $dados : [];
    }

    private function upsertMeta(int $raizId, string $key, string $value): void
    {
        $conn = App::i()->em->getConnection();

        $updated = $conn->executeStatement(
            'UPDATE opportunity_meta SET value = :value WHERE object_id = :id AND key = :key',
            ['value' => $value, 'id' => $raizId, 'key' => $key],
        );
        if ($updated === 0) {
            $conn->executeStatement(
                'INSERT INTO opportunity_meta (object_id, key, value) VALUES (:id, :key, :value)',
                ['id' => $raizId, 'key' => $key, 'value' => $value],
            );
        }
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
