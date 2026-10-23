<?php

namespace AldirBlanc\Services;

use AldirBlanc\Controller;
use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\i;

/**
 * De/para por edital: liga cada coluna "prevista" da planilha acao_cultural_realizada a um campo
 * da ficha de inscrição, já que o título e a existência do campo variam de edital para edital.
 */
class ResultadoDeParaService
{
    /**
     * Colunas que vêm de campo da ficha. Ficam fora: automáticos (cpf, valor da categoria) e os de
     * pós-execução (datas efetivas, recebimento, cpfs remunerados), digitados direto na planilha.
     */
    public const COLUNAS = [
        'qtd_pessoas_remuneradas_exec' => ['tipos' => ['number'], 'termos' => ['remunerad']],
        'formato_execucao' => ['tipos' => ['select', 'checkbox', 'checkboxes', 'text'], 'termos' => ['formato', 'modalidade', 'presencial']],
        'cep_local_execucao' => ['tipos' => ['text'], 'termos' => ['cep']],
        'recebeu_recursos_outras_fontes' => ['tipos' => ['select', 'checkbox', 'checkboxes'], 'termos' => ['outras fontes', 'outros recursos', 'contrapartida']],
        'valor_recursos_outras_fontes' => ['tipos' => ['currency', 'number'], 'termos' => ['valor de outras fontes', 'valor outras fontes', 'valor da contrapartida', 'valor contrapartida']],
    ];

    /** Os campos da ficha de todas as fases do edital, que alimentam os seletores do de/para. */
    public function camposDaFicha(Opportunity $raiz): array
    {
        $campos = [];

        foreach ($raiz->allPhases as $fase) {
            foreach ($fase->registrationFieldConfigurations as $campo) {
                if ($campo->fieldType === 'section') {
                    continue;
                }

                $campos[] = [
                    'id' => (int) $campo->id,
                    'title' => (string) $campo->title,
                    'fieldType' => (string) $campo->fieldType,
                    'fase' => (string) $fase->name,
                ];
            }
        }

        return $campos;
    }

    public function colunas(): array
    {
        $labels = [
            'qtd_pessoas_remuneradas_exec' => i::__('Quantidade de pessoas remuneradas na execução'),
            'formato_execucao' => i::__('Formato de execução'),
            'cep_local_execucao' => i::__('CEP do local de execução'),
            'recebeu_recursos_outras_fontes' => i::__('Recebeu recursos de outras fontes?'),
            'valor_recursos_outras_fontes' => i::__('Valor de recursos de outras fontes'),
        ];

        $colunas = [];
        foreach (self::COLUNAS as $chave => $_def) {
            $colunas[] = ['chave' => $chave, 'label' => $labels[$chave] ?? $chave];
        }

        return $colunas;
    }

    /** Mapeamento salvo (coluna => id do campo da ficha, ou null para "não se aplica"). */
    public function mapeamento(int $raizId): array
    {
        return $this->metaJson($raizId, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_DE_PARA);
    }

    /** Pré-seleção por título normalizado + tipo do campo; é palpite, o gestor revisa e corrige. */
    public function sugestao(array $campos): array
    {
        $sugestao = [];

        foreach (self::COLUNAS as $chave => $def) {
            $sugestao[$chave] = $this->melhorCampo($campos, $def);
        }

        return $sugestao;
    }

    /** Validado contra os campos reais do edital e persistido na raiz; devolve o mapeamento gravado. */
    public function salvarMapeamento(Opportunity $raiz, array $mapeamento): array
    {
        $idsValidos = array_column($this->camposDaFicha($raiz), 'id');

        $limpo = [];
        foreach (self::COLUNAS as $chave => $_def) {
            $valor = $mapeamento[$chave] ?? null;
            $limpo[$chave] = ($valor !== null && in_array((int) $valor, $idsValidos, true)) ? (int) $valor : null;
        }

        $this->upsertMeta(
            (int) $raiz->id,
            Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_DE_PARA,
            json_encode($limpo, JSON_UNESCAPED_UNICODE),
        );

        return $limpo;
    }

    private function melhorCampo(array $campos, array $def): ?int
    {
        foreach ($campos as $campo) {
            if (!in_array($campo['fieldType'], $def['tipos'], true)) {
                continue;
            }

            $titulo = $this->normalizar($campo['title']);
            foreach ($def['termos'] as $termo) {
                if (str_contains($titulo, $termo)) {
                    return $campo['id'];
                }
            }
        }

        return null;
    }

    private function normalizar(string $titulo): string
    {
        $t = mb_strtolower($titulo, 'UTF-8');
        $t = preg_replace('/^\s*\d+[.)]\s*/', '', $t);
        $t = strtr($t, ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);

        return trim($t);
    }

    private function metaJson(int $raizId, string $key): array
    {
        $bruto = App::i()->em->getConnection()->fetchOne(
            'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => $raizId, 'key' => $key],
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
}
