<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Services\LinhasBeneficiariosService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\LoadsConectaResponses;

/** Linha da planilha de contemplados: fonte de cada célula, pendências pelo modelo e forma do envio. */
class LinhasBeneficiariosServiceTest extends TestCase
{
    use LoadsConectaResponses;

    /** Linha aceita pelo CultBR (201), com a coluna condicional e a opcional vazias. */
    private const ACEITA = [
        'tipo_agente' => 'Pessoa física',
        'cnpj' => '',
        'cpf' => '52998224725',
        'cota' => 'Proposta selecionada em ampla concorrência',
        'raca_etnia' => 'Parda',
        'pessoa_com_deficiencia' => 'Não',
        'valor_total_subsidio' => '15000.00',
        'tipo_espaco_cultural' => 'Atelier',
        'cep_espaco_cultural' => '70070-000',
        'povos_comunidades_tradicionais' => '',
    ];

    private function service(?array $colunas = null): LinhasBeneficiariosService
    {
        return new LinhasBeneficiariosService($colunas ?? $this->respostaDaConecta('modelo-pontos-de-cultura')['campos']);
    }

    private function linha(array $daFonte = [], array $override = []): array
    {
        return $this->service()->mesclar($daFonte + self::ACEITA, $override);
    }

    /** Uma coluna só, do tipo e da obrigatoriedade informados. */
    private function pendenciaDaColuna(string $tipo, string $obrigatoriedade, mixed $valor, ?array $opcoes = null): ?string
    {
        $service = $this->service([['coluna' => 'qualquer', 'tipo' => $tipo, 'obrigatoriedade' => $obrigatoriedade, 'opcoes' => $opcoes]]);

        return $service->pendencias($service->mesclar(['qualquer' => $valor], []))['qualquer'] ?? null;
    }

    public function testLinhaTrazAsColunasDoModeloNaOrdemDele(): void
    {
        $this->assertSame(array_keys(self::ACEITA), array_keys($this->linha()));
    }

    public function testCelulaDoGestorVenceADaFonte(): void
    {
        $linha = $this->linha(['raca_etnia' => 'Preta'], ['raca_etnia' => 'Indígena']);

        $this->assertSame(['valor' => 'Indígena', 'fonte' => LinhasBeneficiariosService::FONTE_GESTOR], $linha['raca_etnia']);
        $this->assertSame(['valor' => 'Não', 'fonte' => LinhasBeneficiariosService::FONTE_FICHA], $linha['pessoa_com_deficiencia']);
    }

    public function testCelulaApagadaPeloGestorNaoVoltaASerPreenchidaPelaFonte(): void
    {
        $linha = $this->linha(['raca_etnia' => 'Preta'], ['raca_etnia' => '']);

        $this->assertSame(['valor' => null, 'fonte' => LinhasBeneficiariosService::FONTE_GESTOR], $linha['raca_etnia']);
    }

    public function testColunaSemFonteFicaVazia(): void
    {
        $linha = $this->service()->mesclar([], []);

        $this->assertNull($linha['raca_etnia']['valor']);
        $this->assertSame(LinhasBeneficiariosService::FONTE_FICHA, $linha['raca_etnia']['fonte']);
    }

    public function testLinhaAceitaPeloCultBrNaoTemPendenciaEVaiSemAsColunasVazias(): void
    {
        $linha = $this->linha();

        $this->assertSame([], $this->service()->pendencias($linha));
        $this->assertSame(array_filter(self::ACEITA), $this->service()->paraOEnvio($linha));
    }

    public function testValoresQueOCultBrRecusaSaoPendencia(): void
    {
        $linha = $this->linha(['cpf' => '52998224726', 'cota' => 'Outra cota', 'valor_total_subsidio' => 'quinze mil', 'cep_espaco_cultural' => '123']);

        $this->assertSame([
            'cpf' => 'CPF inválido',
            'cota' => 'O valor não é uma das opções do CultBR.',
            'valor_total_subsidio' => 'O valor não é um número.',
            'cep_espaco_cultural' => 'CEP incompleto',
        ], $this->service()->pendencias($linha));
    }

    public function testMonetarioNegativoPendeComOMotivo(): void
    {
        $this->assertSame(['valor_total_subsidio' => 'O valor em reais não pode ser negativo.'], $this->service()->pendencias($this->linha(['valor_total_subsidio' => -1500])));
    }

    public function testObrigatoriaVaziaPendeEAsDemaisNao(): void
    {
        $linha = $this->linha(['raca_etnia' => null, 'cnpj' => null, 'povos_comunidades_tradicionais' => null]);

        $this->assertSame(['raca_etnia' => 'Preenchimento obrigatório.'], $this->service()->pendencias($linha));
    }

    public function testEquivalenciaTrocaSoOValorDaFonte(): void
    {
        $equivalencias = ['raca_etnia' => ['Negra' => 'Preta'], 'tipo_agente' => ['MEI' => 'Microempreendedor individual - MEI']];
        $service = $this->service();

        $comFonte = $service->mesclar(['raca_etnia' => ' Negra '] + self::ACEITA, [], $equivalencias);
        $doGestor = $service->mesclar(self::ACEITA, ['raca_etnia' => 'Negra'], $equivalencias);
        $semEquivalencia = $service->mesclar(['raca_etnia' => 'negra'] + self::ACEITA, [], $equivalencias);

        $this->assertSame('Preta', $comFonte['raca_etnia']['valor']);
        $this->assertSame([], $service->pendencias($comFonte));
        $this->assertSame('Negra', $doGestor['raca_etnia']['valor'], 'a célula do gestor não passa pela equivalência');
        $this->assertSame(['raca_etnia'], array_keys($service->pendencias($semEquivalencia)), 'maiúscula diferente é outro valor');
        $this->assertSame(['Negra'], $service->mesclar(['raca_etnia' => ['Negra']], [], $equivalencias)['raca_etnia']['valor'], 'vários valores não têm equivalência');
    }

    public function testListaDiferenciaMaiusculas(): void
    {
        $linha = $this->linha(['tipo_agente' => 'pessoa física', 'raca_etnia' => 'parda']);

        $this->assertSame(['tipo_agente', 'raca_etnia'], array_keys($this->service()->pendencias($linha)));
    }

    public function testColunaSemRegraAceitaQualquerTexto(): void
    {
        $linha = $this->linha(['povos_comunidades_tradicionais' => 'Quilombola']);

        $this->assertSame([], $this->service()->pendencias($linha));
        $this->assertSame('Quilombola', $this->service()->paraOEnvio($linha)['povos_comunidades_tradicionais']);
    }

    #[DataProvider('respostasSemValorUnico')]
    public function testRespostaQueNaoEUmValorSoPendeENaoVaiNoEnvio(mixed $resposta): void
    {
        $linha = $this->linha(['cota' => $resposta]);

        $this->assertSame(['cota' => 'A resposta da ficha traz mais de um valor. Informe um só.'], $this->service()->pendencias($linha));
        $this->assertArrayNotHasKey('cota', $this->service()->paraOEnvio($linha));
    }

    public static function respostasSemValorUnico(): array
    {
        return [
            'múltipla escolha' => [['Proposta selecionada em ampla concorrência']],
            'endereço' => [(object) ['endereco' => 'Rua A', 'cep' => '70070-000']],
        ];
    }

    #[DataProvider('respostasVazias')]
    public function testRespostaVaziaEColunaVazia(mixed $resposta): void
    {
        $this->assertNull($this->linha(['cota' => $resposta])['cota']['valor']);
    }

    public static function respostasVazias(): array
    {
        return ['nulo' => [null], 'texto vazio' => ['  '], 'lista vazia' => [[]], 'objeto vazio' => [(object) []], 'não marcado' => [false], 'marcado' => [true]];
    }

    #[DataProvider('documentos')]
    public function testDocumentoPerdeSoAPontuacao(string $coluna, string $informado, string $esperado): void
    {
        $linha = $this->linha([$coluna => $informado]);

        $this->assertSame($esperado, $linha[$coluna]['valor']);
        $this->assertSame([], $this->service()->pendencias($linha));
    }

    public static function documentos(): array
    {
        return [
            'CPF com máscara' => ['cpf', '529.982.247-25', '52998224725'],
            'CNPJ com máscara' => ['cnpj', '11.222.333/0001-81', '11222333000181'],
            'CNPJ alfanumérico' => ['cnpj', '12.abc.345/01de-35', '12ABC34501DE35'],
            'CEP fica como veio' => ['cep_espaco_cultural', '70070000', '70070000'],
        ];
    }

    #[DataProvider('valores')]
    public function testValorMonetarioVaiComPontoEDuasCasas(mixed $informado, string $esperado): void
    {
        $linha = $this->linha(['valor_total_subsidio' => $informado]);

        $this->assertSame($esperado, $this->service()->paraOEnvio($linha)['valor_total_subsidio']);
        $this->assertSame([], $this->service()->pendencias($linha));
    }

    public static function valores(): array
    {
        return [
            'número da ficha' => [15000.0, '15000.00'],
            'inteiro' => [15000, '15000.00'],
            'texto em reais' => ['R$ 15.000,00', '15000.00'],
            'texto com milhar sem centavos' => ['15.000', '15000.00'],
            'texto com milhões' => ['1.234.567,8', '1234567.80'],
            'texto só com centavos' => ['1500,5', '1500.50'],
            'texto com ponto decimal' => ['1500.5', '1500.50'],
            'texto inteiro' => ['1500', '1500.00'],
        ];
    }

    #[DataProvider('valoresInvalidos')]
    public function testValorQueNaoENumeroFicaComoVeioEPende(mixed $informado, string $mostrado): void
    {
        $linha = $this->linha(['valor_total_subsidio' => $informado]);

        $this->assertSame($mostrado, $linha['valor_total_subsidio']['valor']);
        $this->assertSame(['valor_total_subsidio'], array_keys($this->service()->pendencias($linha)));
    }

    public static function valoresInvalidos(): array
    {
        return [
            'por extenso' => ['quinze mil', 'quinze mil'],
            'milhar quebrado' => ['1500.000', '1500.000'],
            'três casas' => ['15,000', '15,000'],
            'negativo' => [-10.0, '-10.00'],
        ];
    }

    #[DataProvider('tiposConhecidos')]
    public function testTipoConhecidoVazioSoPendeQuandoObrigatorio(string $tipo): void
    {
        $this->assertSame('Preenchimento obrigatório.', $this->pendenciaDaColuna($tipo, 'obrigatorio', ''));

        foreach (['condicional', 'opcional', 'nao_informado'] as $obrigatoriedade) {
            $this->assertNull($this->pendenciaDaColuna($tipo, $obrigatoriedade, ''), $obrigatoriedade);
        }
    }

    public static function tiposConhecidos(): array
    {
        return [['cpf'], ['cnpj'], ['cep'], ['monetario'], ['lista'], ['texto'], ['a_definir']];
    }

    #[DataProvider('declaracoesDesconhecidas')]
    public function testTipoOuObrigatoriedadeDesconhecidaPendePreenchidaOuNao(string $tipo, string $obrigatoriedade): void
    {
        foreach (['', 'Parda'] as $valor) {
            $this->assertStringContainsString('ainda não reconhece', (string) $this->pendenciaDaColuna($tipo, $obrigatoriedade, $valor));
        }
    }

    public static function declaracoesDesconhecidas(): array
    {
        return ['tipo novo' => ['data', 'opcional'], 'obrigatoriedade nova' => ['texto', 'recomendado'], 'sem obrigatoriedade' => ['texto', '']];
    }

    public function testListaSemOpcoesPende(): void
    {
        $this->assertNotNull($this->pendenciaDaColuna('lista', 'opcional', 'Parda'));
        $this->assertNull($this->pendenciaDaColuna('lista', 'opcional', 'Parda', ['Parda']));
    }
}
