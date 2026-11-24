<?php

namespace AldirBlanc\Services;

/** Regras de visibilidade que o PAR declara nas questões, avaliadas como ele as avalia. */
final class FormularioRegras
{
    private const TIPO = 'visibilidade';
    private const EFEITO = ['mostrar' => true];
    private const IGUAL = 'eq';
    private const CONTEM = 'includes';

    public function __construct(private readonly FormularioEstrutura $estrutura)
    {
    }

    /** Com mais de uma regra, todas precisam casar; a regra não suportada não esconde nada. */
    public function isVisivel(array $questao, array $respostas): bool
    {
        foreach ($this->regras($questao) as $regra) {
            if ($this->isSuportada($regra) && !$this->casa($regra, $respostas)) {
                return false;
            }
        }

        return true;
    }

    /** Toda regra da questão é conhecida? Sem isso não dá para saber se ela é cobrada. */
    public function isTodaSuportada(array $questao): bool
    {
        return array_filter($this->regras($questao), fn(array $regra) => !$this->isSuportada($regra)) === [];
    }

    private function regras(array $questao): array
    {
        return $questao['config']['regras']['itens'] ?? [];
    }

    private function isSuportada(array $regra): bool
    {
        return ($regra['tipo'] ?? null) === self::TIPO
            && ($regra['efeito'] ?? null) === self::EFEITO
            && in_array($regra['quando']['operador'] ?? null, [self::IGUAL, self::CONTEM], true)
            && $this->fonte($regra) !== null;
    }

    private function fonte(array $regra): ?array
    {
        return $this->estrutura->questaoPorEtapa((int) ($regra['quando']['fonteIdQuestaoEtapa'] ?? 0));
    }

    private function casa(array $regra, array $respostas): bool
    {
        $resposta = $respostas[$this->fonte($regra)['id_questao']] ?? null;
        $esperado = $regra['quando']['valor'] ?? null;

        if ($regra['quando']['operador'] === self::IGUAL) {
            return self::marcado($resposta) === $esperado;
        }

        return is_array($resposta) && in_array($esperado, array_map(self::marcado(...), $resposta), true);
    }

    /** A opção com complemento é gravada como {id, complemento}; as demais, pelo valor. */
    private static function marcado(mixed $escolha): mixed
    {
        return is_array($escolha) ? ($escolha['id'] ?? null) : $escolha;
    }
}
