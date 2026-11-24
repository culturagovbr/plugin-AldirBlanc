<?php

namespace AldirBlanc\Services;

use MapasCulturais\i;

/** O que o CultBR recusou, lido pela forma da resposta e sem o valor que ele recusou. */
final class ResultadoRecusas
{
    private const TETO_DE_ITENS = 100;

    private const CONTEUDO_GRANDE_DEMAIS = 413;

    /**
     * @param list<array> $itens uma recusa por questão, por célula da planilha ou geral
     * @param bool $isDeOutraVersao a resposta tem questão que não é da versão vigente do formulário
     */
    private function __construct(
        public readonly string $resumo,
        public readonly array $itens = [],
        public readonly bool $isDeOutraVersao = false,
    ) {
    }

    /**
     * Recusas da resposta de erro, com as questões nomeadas pela estrutura vigente.
     * @param array{inscricoes?: list<string>, colunas?: array<string, string>} $planilha a quem pertence cada linha enviada e o nome de cada coluna
     */
    public static function daResposta(?int $status, ?string $corpo, ?array $estrutura, array $planilha = []): self
    {
        $resposta = json_decode((string) $corpo, true);
        $resposta = is_array($resposta) ? $resposta : [];
        $leitura = new FormularioEstrutura($estrutura);
        $detalhe = $resposta['detail'] ?? null;

        return match (true) {
            is_array($resposta['errors'] ?? null) => self::deQuestoes($resposta['errors'], $leitura),
            is_array($resposta['inconsistencias'] ?? null) => self::deLinhas($resposta['inconsistencias'], $planilha),
            is_string($detalhe) && trim($detalhe) !== '' => new self($detalhe),
            is_array($detalhe) && isset($detalhe['message']) => self::deDetalhe($detalhe, $leitura),
            is_array($detalhe) && $detalhe !== [] => self::deCampos($detalhe),
            $status === self::CONTEUDO_GRANDE_DEMAIS => new self(i::__('O envio passou do tamanho que o CultBR aceita.')),
            default => new self(sprintf(i::__('O CultBR recusou o envio (HTTP %s).'), $status)),
        };
    }

    /** A inconsistência da planilha sem o valor que o CultBR recebeu. */
    public static function semValorRecebido(array $inconsistencia): array
    {
        $descricao = (string) ($inconsistencia['descricao'] ?? '');

        foreach ((array) ($inconsistencia['valor_recebido'] ?? []) as $valor) {
            $descricao = self::semOcorrencia($descricao, is_scalar($valor) ? (string) $valor : '');
        }

        unset($inconsistencia['valor_recebido']);

        return ['descricao' => $descricao] + $inconsistencia;
    }

    /** Só a ocorrência inteira: tirar "1" não pode estragar "1500.00". */
    private static function semOcorrencia(string $texto, string $valor): string
    {
        if ($valor === '') {
            return $texto;
        }

        return (string) preg_replace('/(?<![\p{L}\p{N}])' . preg_quote($valor, '/') . '(?![\p{L}\p{N}])/u', '…', $texto);
    }

    private static function deQuestoes(array $erros, FormularioEstrutura $leitura): self
    {
        $itens = array_map(fn(array $erro) => self::questao(
            (int) str_replace('questao:', '', (string) ($erro['field'] ?? '')),
            (string) ($erro['message'] ?? ''),
            $leitura,
        ), self::ateOTeto(array_filter($erros, is_array(...))));

        return new self(i::__('O CultBR recusou respostas do formulário.'), $itens);
    }

    private static function questao(int $idQuestaoEtapa, string $descricao, FormularioEstrutura $leitura): array
    {
        return [
            'tipo' => 'questao',
            'nome' => $leitura->questaoPorEtapa($idQuestaoEtapa)['nome'] ?? sprintf(i::__('Questão %d'), $idQuestaoEtapa),
            'descricao' => $descricao,
        ];
    }

    private static function deLinhas(array $inconsistencias, array $planilha): self
    {
        $itens = array_map(function (array $inconsistencia) use ($planilha) {
            $limpa = self::semValorRecebido($inconsistencia);
            $indice = $limpa['indice'] ?? null;
            $coluna = $limpa['coluna'] ?? null;

            return [
                'tipo' => 'linha',
                'indice' => $indice,
                'inscricao' => $planilha['inscricoes'][$indice] ?? null,
                'coluna' => $planilha['colunas'][$coluna] ?? $coluna,
                'regra' => $limpa['regra'] ?? null,
                'descricao' => $limpa['descricao'],
            ];
        }, self::ateOTeto(array_filter($inconsistencias, is_array(...))));

        return new self(i::__('O CultBR encontrou inconsistências na planilha de contemplados, e nenhuma linha foi salva.'), $itens);
    }

    private static function deDetalhe(array $detalhe, FormularioEstrutura $leitura): self
    {
        $ids = is_array($detalhe['ids'] ?? null) ? $detalhe['ids'] : [];
        $itens = array_map(fn(mixed $id) => self::questao((int) $id, '', $leitura), self::ateOTeto($ids));

        return new self((string) $detalhe['message'], $itens, $ids !== []);
    }

    private static function deCampos(array $campos): self
    {
        $itens = array_map(fn(mixed $campo) => [
            'tipo' => 'geral',
            'nome' => is_array($campo) && is_array($campo['loc'] ?? null) ? implode('.', $campo['loc']) : '',
            'descricao' => is_array($campo) ? (string) ($campo['msg'] ?? '') : (string) $campo,
        ], self::ateOTeto($campos));

        return new self(i::__('O CultBR recusou o envio.'), $itens);
    }

    private static function ateOTeto(array $itens): array
    {
        return array_slice(array_values($itens), 0, self::TETO_DE_ITENS);
    }
}
