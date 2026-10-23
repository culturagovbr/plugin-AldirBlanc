<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Services\ResultadoDeParaService;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\RegistrationFieldConfiguration;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\SendsOpportunityThroughJob;
use Tests\Traits\UserDirector;

/** De/para por edital: listagem dos campos da ficha, pré-seleção por título+tipo, validação e persistência. */
class ResultadoDeParaServiceTest extends TestCase
{
    use UserDirector;
    use SendsOpportunityThroughJob;

    private function novoEdital(): Opportunity
    {
        return $this->createOpportunity($this->userDirector->createUser());
    }

    private function addCampo(Opportunity $opp, string $title, string $type, int $order = 1): int
    {
        $this->app->disableAccessControl();
        $campo = new RegistrationFieldConfiguration();
        $campo->owner = $opp;
        $campo->title = $title;
        $campo->fieldType = $type;
        $campo->displayOrder = $order;
        $campo->save(true);
        $this->app->enableAccessControl();

        return (int) $campo->id;
    }

    private function service(): ResultadoDeParaService
    {
        return new ResultadoDeParaService();
    }

    function testCamposDaFichaListaOsCamposIgnorandoSecao()
    {
        $edital = $this->novoEdital();
        $this->addCampo($edital, 'CEP do local de execução', 'text', 1);
        $this->addCampo($edital, 'Dados da execução', 'section', 2);
        $this->addCampo($edital, 'Data de início da execução', 'date', 3);

        $campos = $this->service()->camposDaFicha($edital);
        $titulos = array_column($campos, 'title');

        $this->assertContains('CEP do local de execução', $titulos);
        $this->assertContains('Data de início da execução', $titulos);
        $this->assertNotContains('Dados da execução', $titulos, 'seção é cabeçalho, não entra como campo');
        $this->assertSame('date', $campos[array_search('Data de início da execução', $titulos, true)]['fieldType']);
    }

    function testSugestaoPreSelecionaPorTituloETipo()
    {
        $edital = $this->novoEdital();
        $cep = $this->addCampo($edital, 'CEP do local de execução', 'text', 1);
        $remuneradas = $this->addCampo($edital, '5. Quantas pessoas serão remuneradas?', 'number', 2);

        $sugestao = $this->service()->sugestao($this->service()->camposDaFicha($edital));

        $this->assertSame($cep, $sugestao['cep_local_execucao'], 'o título em maiúsculas casa pelo termo minúsculo');
        $this->assertSame($remuneradas, $sugestao['qtd_pessoas_remuneradas_exec'], 'o prefixo numérico "5." não atrapalha o match');
        $this->assertNull($sugestao['formato_execucao'], 'sem campo compatível, a coluna fica sem sugestão');
    }

    function testSugestaoRespeitaOTipoDoCampo()
    {
        $edital = $this->novoEdital();
        $this->addCampo($edital, 'CEP do local de execução', 'number', 1);

        $sugestao = $this->service()->sugestao($this->service()->camposDaFicha($edital));

        $this->assertNull($sugestao['cep_local_execucao'], 'título casa mas o tipo (number) não é de texto: não sugere');
    }

    function testSalvarMapeamentoValidaOsCamposEPersiste()
    {
        $edital = $this->novoEdital();
        $cep = $this->addCampo($edital, 'CEP', 'text', 1);

        $salvo = $this->service()->salvarMapeamento($edital, [
            'cep_local_execucao' => $cep,
            'qtd_pessoas_remuneradas_exec' => 999999,
            'formato_execucao' => null,
        ]);

        $this->assertSame($cep, $salvo['cep_local_execucao']);
        $this->assertNull($salvo['qtd_pessoas_remuneradas_exec'], 'id que não é campo do edital vira null');
        $this->assertNull($salvo['formato_execucao']);
        $this->assertArrayHasKey('recebeu_recursos_outras_fontes', $salvo, 'toda coluna mapeável entra no mapeamento salvo');

        $this->assertSame($salvo, $this->service()->mapeamento((int) $edital->id), 'o mapeamento é lido de volta igual');
    }

    function testSalvarMapeamentoIgnoraColunaDesconhecida()
    {
        $edital = $this->novoEdital();
        $cep = $this->addCampo($edital, 'CEP', 'text', 1);

        $salvo = $this->service()->salvarMapeamento($edital, [
            'cep_local_execucao' => $cep,
            'coluna_inventada' => $cep,
        ]);

        $this->assertArrayNotHasKey('coluna_inventada', $salvo, 'só as colunas conhecidas são persistidas');
        $this->assertCount(count(ResultadoDeParaService::COLUNAS), $salvo);
    }

    function testMapeamentoVazioQuandoNuncaSalvou()
    {
        $edital = $this->novoEdital();

        $this->assertSame([], $this->service()->mapeamento((int) $edital->id));
    }

    function testDocumentoPorTipoTrazTodosOsTiposMesmoSemNadaSalvo()
    {
        $edital = $this->novoEdital();
        $tipos = $this->service()->tiposDeProponente();

        $porTipo = $this->service()->documentoPorTipo((int) $edital->id);

        $this->assertSame($tipos, array_keys($porTipo), 'mapa sem chaves viraria lista no json e o front perderia a escolha');
        $this->assertSame(array_fill(0, count($tipos), null), array_values($porTipo));
    }

    function testDocumentoPorTipoSoAceitaCampoDoProprioEdital()
    {
        $edital = $this->novoEdital();
        $cpf = $this->addCampo($edital, 'CPF', 'cpf', 1);

        $salvo = $this->service()->salvarDocumentoPorTipo($edital, ['Pessoa Física' => $cpf, 'MEI' => 999999]);

        $this->assertSame($cpf, $salvo['Pessoa Física']);
        $this->assertNull($salvo['MEI'], 'campo de outro edital não entra no de/para');
        $this->assertSame($salvo, $this->service()->documentoPorTipo((int) $edital->id));
    }
}
