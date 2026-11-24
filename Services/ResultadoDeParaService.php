<?php

namespace AldirBlanc\Services;

use AldirBlanc\Controller;
use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\i;

/** De/para por edital: de onde vem cada coluna da planilha de contemplados que o CultBR define para ele. */
class ResultadoDeParaService
{
    /** Prefixo da fonte que é um campo da ficha, seguido do id do campo. */
    public const FONTE_CAMPO = 'campo:';
    public const FONTE_TIPO_DE_PROPONENTE = 'inscricao:tipo';
    public const FONTE_FAIXA = 'inscricao:faixa';
    public const FONTE_VALOR_POR_CONTEMPLADO = 'inscricao:valor';

    private const TIPOS_DE_VARIOS_VALORES = ['checklist', 'multiselect'];

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
        return $this->gravado($raizId, $modelo)['fontes'] ?? [];
    }

    /** @return array<string, array<string, string>> por coluna de lista, a opção do modelo para cada valor da fonte */
    public function equivalencias(int $raizId, ?string $modelo): array
    {
        return $this->gravado($raizId, $modelo)['equivalencias'] ?? [];
    }

    /** @return array<string, list<string>> por coluna de lista, os valores possíveis da fonte que não são opção do modelo */
    public function valoresSemOpcao(Opportunity $raiz, array $planilha, array $fontes): array
    {
        $semOpcao = [];

        foreach ($planilha['colunas'] as $coluna) {
            $fonte = $coluna['tipo'] === 'lista' ? $fontes[$coluna['coluna']] ?? '' : '';
            $valores = array_diff($this->valoresPossiveis($raiz, $fonte), $coluna['opcoes'] ?? []);

            if ($valores !== []) {
                $semOpcao[$coluna['coluna']] = array_values(array_unique($valores));
            }
        }

        return $semOpcao;
    }

    /**
     * Guarda só o que aponta para coluna do modelo, fonte do edital e opção declarada; devolve o que ficou gravado.
     * @return array{fontes: array, equivalencias: array}
     */
    public function salvar(Opportunity $raiz, array $planilha, array $fontesRecebidas, array $equivalenciasRecebidas = []): array
    {
        $fontes = $this->fontesAceitas($raiz, $planilha, $fontesRecebidas);
        $gravado = [
            'fontes' => $fontes,
            'equivalencias' => $this->equivalenciasAceitas($raiz, $planilha, $fontes, $equivalenciasRecebidas),
        ];

        $this->upsertMeta(
            (int) $raiz->id,
            Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_PLANILHA_DE_PARA,
            json_encode(['oportunidade' => (int) $raiz->id, 'modelo' => $planilha['modelo']] + $gravado, JSON_UNESCAPED_UNICODE),
        );

        return $gravado;
    }

    private function gravado(int $raizId, ?string $modelo): array
    {
        $gravado = $this->metaJson($raizId, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_PLANILHA_DE_PARA);

        // Duplicar ou gerar edital copia o metadado, e outro modelo tem outras colunas.
        return ($gravado['oportunidade'] ?? null) === $raizId && ($gravado['modelo'] ?? null) === $modelo ? $gravado : [];
    }

    private function fontesAceitas(Opportunity $raiz, array $planilha, array $recebidas): array
    {
        $validas = $this->fontesValidas($raiz);
        $fontes = [];

        foreach ($planilha['colunas'] as $coluna) {
            $fonte = $this->fonteAceita($coluna, $recebidas[$coluna['coluna']] ?? null, $validas);

            if ($fonte !== null && $fonte !== []) {
                $fontes[$coluna['coluna']] = $fonte;
            }
        }

        return $fontes;
    }

    private function equivalenciasAceitas(Opportunity $raiz, array $planilha, array $fontes, array $recebidas): array
    {
        $opcoes = array_column($planilha['colunas'], 'opcoes', 'coluna');
        $aceitas = [];

        foreach ($this->valoresSemOpcao($raiz, $planilha, $fontes) as $coluna => $valores) {
            $porValor = array_intersect_key((array) ($recebidas[$coluna] ?? []), array_flip($valores));
            $aceitas[$coluna] = array_filter($porValor, fn(mixed $opcao) => in_array($opcao, $opcoes[$coluna] ?? [], true));
        }

        return array_filter($aceitas);
    }

    /** Tirados da configuração, não das inscrições; texto livre e múltipla escolha não têm. */
    private function valoresPossiveis(Opportunity $raiz, string $fonte): array
    {
        return match ($fonte) {
            self::FONTE_TIPO_DE_PROPONENTE => $this->tiposDeProponente(),
            self::FONTE_FAIXA => (new FaixasDoEdital($raiz->registrationRanges))->rotulos(),
            default => str_starts_with($fonte, self::FONTE_CAMPO) ? $this->opcoesDoCampo((int) substr($fonte, strlen(self::FONTE_CAMPO))) : [],
        };
    }

    private function opcoesDoCampo(int $campoId): array
    {
        $app = App::i();
        $campo = $app->repo('RegistrationFieldConfiguration')->find($campoId);
        $campo?->owner->registerRegistrationMetadata();

        $definicao = $campo ? $app->getRegisteredMetadata(Registration::class)[$campo->fieldName] ?? null : null;

        if ($definicao === null || in_array($definicao->type, self::TIPOS_DE_VARIOS_VALORES, true)) {
            return [];
        }

        $valores = array_map(fn(int|string $valor) => trim((string) $valor), array_keys($definicao->config['options'] ?? []));

        return array_filter($valores, fn(string $valor) => $valor !== '');
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
