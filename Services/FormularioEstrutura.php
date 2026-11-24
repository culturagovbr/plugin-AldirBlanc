<?php

namespace AldirBlanc\Services;

/** Estrutura do formulário de resultado publicada pelo PAR, com as questões por id. */
final class FormularioEstrutura
{
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

    /** @return list<array> opções da questão; nem toda `opcao` é lista de itens */
    public static function itens(array $questao): array
    {
        return $questao['opcao']['itens'] ?? [];
    }
}
