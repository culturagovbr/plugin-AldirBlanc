<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Services\BeneficiariosService;
use AldirBlanc\Services\LinhasDoResultadoService;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\BuildsContempladas;
use Tests\AldirBlanc\Traits\BuildsPublishedFinalPhase;
use Tests\Traits\UserDirector;

/** Planilha de contemplados do envio: só vai quando o conjunto inteiro passa no que o validador exige. */
class LinhasDoResultadoServiceTest extends TestCase
{
    use UserDirector;
    use BuildsPublishedFinalPhase;
    use BuildsContempladas;

    private const RESTANTE = [
        'tipo_agente' => 'Pessoa física',
        'cota' => 'Proposta selecionada em ampla concorrência',
        'raca_etnia' => 'Parda',
        'pessoa_com_deficiencia' => 'Não',
        'valor_total_subsidio' => '15000.00',
        'tipo_espaco_cultural' => 'Atelier',
        'cep_espaco_cultural' => '70070-000',
    ];

    private function service(): LinhasDoResultadoService
    {
        return new LinhasDoResultadoService($this->planilha());
    }

    /** Contemplada com tudo o que o modelo cobra, menos o documento, que vem da ficha. */
    private function completa(Opportunity $raiz, Opportunity $fase, string $cpf): Registration
    {
        $contemplada = $this->contempladaComDocumento($raiz, $fase, $cpf);
        (new BeneficiariosService($this->planilha()))->salvarCelulas($raiz, (int) $contemplada->id, self::RESTANTE);

        return $contemplada;
    }

    public function testMontaUmaLinhaPorContempladaComAsColunasDoModelo(): void
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $this->completa($raiz, $fase, '529.982.247-25');

        $this->assertEquals([self::RESTANTE + ['cpf' => '52998224725']], $this->service()->montar($raiz));
    }

    public function testUmaContempladaPendenteSeguraAPlanilhaInteira(): void
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $this->completa($raiz, $fase, '529.982.247-25');
        $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');

        $this->assertNull($this->service()->montar($raiz), 'o validador é atômico: linha pendente derrubaria o resultado');
    }

    function testEditalSemContempladaNaoGeraPlanilha()
    {
        [$raiz] = $this->editalComCategorias();

        $this->assertNull($this->service()->montar($raiz));
    }

    public function testLinhasSaemNaOrdemDoNumeroDaInscricao(): void
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $cpfPorNumero = [
            (string) $this->completa($raiz, $fase, '111.444.777-35')->number => '11144477735',
            (string) $this->completa($raiz, $fase, '529.982.247-25')->number => '52998224725',
        ];
        ksort($cpfPorNumero, SORT_STRING);

        $this->assertSame(array_values($cpfPorNumero), array_column($this->service()->montar($raiz), 'cpf'), 'ordem instável geraria retificado sem nada ter mudado');
    }
}
