<?php

namespace AldirBlanc\Services;

use AldirBlanc\Controller;
use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\i;

/** De/para por edital: de onde vem cada coluna da planilha de contemplados que o CultBR define para ele. */
class ResultadoDeParaService
{
    /** Prefixo da fonte que é um campo da ficha, seguido do id do campo. */
    public const FONTE_CAMPO = 'campo:';
    public const FONTE_TIPO_DE_PROPONENTE = 'inscricao:tipo';
    public const FONTE_FAIXA = 'inscricao:faixa';
    public const FONTE_VALOR_POR_CONTEMPLADO = 'inscricao:valor';

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
                    'etapa' => (string) ($campo->step?->name ?? ''),
                ];
            }
        }

        return $campos;
    }

    /** Todos os tipos de proponente do sistema: a inscrição pode trazer tipo que o edital já não aceita mais. */
    public function tiposDeProponente(): array
    {
        return array_values(array_map('strval', App::i()->config['registration.proponentTypes'] ?? []));
    }

    /** Dados da própria inscrição que também servem de fonte. */
    public function dadosDaInscricao(): array
    {
        return [
            ['fonte' => self::FONTE_TIPO_DE_PROPONENTE, 'title' => i::__('Tipo de proponente')],
            ['fonte' => self::FONTE_FAIXA, 'title' => i::__('Faixa')],
            ['fonte' => self::FONTE_VALOR_POR_CONTEMPLADO, 'title' => i::__('Valor por contemplado (valor da faixa dividido pelas vagas)')],
        ];
    }

    /** Documento tem um campo por tipo de proponente: quem responde o CPF não é quem responde o CNPJ. */
    public static function isPorTipoDeProponente(array $coluna): bool
    {
        return in_array($coluna['tipo'], ['cpf', 'cnpj'], true);
    }

    /**
     * De onde vem cada coluna do modelo; vazio enquanto o gestor não mapeou este modelo.
     * @return array<string, string|array<string, string>> fonte por coluna, ou fonte por tipo de proponente
     */
    public function fontes(int $raizId, ?string $modelo): array
    {
        $gravado = $this->metaJson($raizId, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_PLANILHA_DE_PARA);

        // Duplicar ou gerar edital copia o metadado, e outro modelo tem outras colunas.
        return ($gravado['oportunidade'] ?? null) === $raizId && ($gravado['modelo'] ?? null) === $modelo
            ? $gravado['fontes'] ?? []
            : [];
    }

    /** Guarda só o que aponta para coluna do modelo e fonte do edital; devolve o que ficou gravado. */
    public function salvarFontes(Opportunity $raiz, array $planilha, array $recebidas): array
    {
        $validas = $this->fontesValidas($raiz);
        $fontes = [];

        foreach ($planilha['colunas'] as $coluna) {
            $fonte = $this->fonteAceita($coluna, $recebidas[$coluna['coluna']] ?? null, $validas);

            if ($fonte !== null && $fonte !== []) {
                $fontes[$coluna['coluna']] = $fonte;
            }
        }

        $this->upsertMeta(
            (int) $raiz->id,
            Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_PLANILHA_DE_PARA,
            json_encode(['oportunidade' => (int) $raiz->id, 'modelo' => $planilha['modelo'], 'fontes' => $fontes], JSON_UNESCAPED_UNICODE),
        );

        return $fontes;
    }

    private function fonteAceita(array $coluna, mixed $fonte, array $validas): string|array|null
    {
        if (!self::isPorTipoDeProponente($coluna)) {
            return in_array($fonte, $validas, true) ? $fonte : null;
        }

        $porTipo = array_intersect_key((array) $fonte, array_flip($this->tiposDeProponente()));

        return array_filter($porTipo, fn(mixed $campo) => in_array($campo, $validas, true) && str_starts_with($campo, self::FONTE_CAMPO));
    }

    /** @return list<string> */
    private function fontesValidas(Opportunity $raiz): array
    {
        return [
            ...array_map(fn(int $id) => self::FONTE_CAMPO . $id, array_column($this->camposDaFicha($raiz), 'id')),
            ...array_column($this->dadosDaInscricao(), 'fonte'),
        ];
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
