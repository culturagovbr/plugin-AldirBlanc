<?php

namespace AldirBlanc\Services;

/** Estrutura do formulário de resultado publicada pelo PAR, com as questões por id. */
final class FormularioEstrutura
{
    private const TIPOS_DE_ANEXO = ['anexo', 'múltiplos anexos'];

    private const NOME_DA_PLANILHA = 'Planilha de resultados';

    /** @var array<int, array> por id_questao, na ordem do formulário */
    private array $questoes = [];

    /** @var array<int, int> id_questao por id_questao_etapa */
    private array $idPorEtapa = [];

    public function __construct(private readonly ?array $estrutura)
    {
        foreach ($estrutura['secoes'] ?? [] as $secao) {
            foreach ($secao['etapas'] ?? [] as $etapa) {
                foreach ($etapa['questoes'] ?? [] as $questao) {
                    $this->inclui($questao);
                }
            }
        }
    }

    private function inclui(array $questao): void
    {
        $id = (int) $questao['id_questao'];
        $this->questoes[$id] = $questao;

        if (isset($questao['id_questao_etapa'])) {
            $this->idPorEtapa[(int) $questao['id_questao_etapa']] = $id;
        }
    }

    /** @return array<int, array> questões por id_questao */
    public function questoes(): array
    {
        return $this->questoes;
    }

    /** Questão pelo id com que o CultBR a endereça no envio e nas regras. */
    public function questaoPorEtapa(int $idQuestaoEtapa): ?array
    {
        return $this->questoes[$this->idPorEtapa[$idQuestaoEtapa] ?? null] ?? null;
    }

    /** Id da versão publicada do formulário, ou null quando a estrutura não a informa. */
    public function versao(): ?int
    {
        $versao = $this->estrutura['id_formulario_versao'] ?? null;

        return $versao === null ? null : (int) $versao;
    }

    /** Questão que pede a planilha de contemplados; ela é montada pelo sistema, nunca anexada. */
    public static function isPlanilha(array $questao): bool
    {
        return in_array($questao['tipo_questao'] ?? '', self::TIPOS_DE_ANEXO, true)
            && str_contains((string) ($questao['tag'] ?? ''), 'planilha')
            && mb_stripos((string) ($questao['nome'] ?? ''), self::NOME_DA_PLANILHA) === 0;
    }

    /** Questão que pede um documento do gestor em arquivo. */
    public static function isDocumento(array $questao): bool
    {
        return in_array($questao['tipo_questao'] ?? '', self::TIPOS_DE_ANEXO, true) && !self::isPlanilha($questao);
    }

    /** @return list<array> opções da questão; nem toda `opcao` é lista de itens */
    public static function itens(array $questao): array
    {
        return $questao['opcao']['itens'] ?? [];
    }
}
