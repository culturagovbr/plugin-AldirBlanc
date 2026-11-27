<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Services\BeneficiariosService;
use AldirBlanc\Services\LinhasBeneficiariosService;
use AldirBlanc\Services\ResultadoDeParaService;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\BuildsContempladas;
use Tests\AldirBlanc\Traits\BuildsPublishedFinalPhase;
use Tests\Traits\UserDirector;

/** Página da grade de beneficiários: contempladas da fase final com as colunas que o sistema resolve. */
class BeneficiariosServiceTest extends TestCase
{
    use UserDirector;
    use BuildsPublishedFinalPhase;
    use BuildsContempladas;

    private function service(string $resposta = 'modelo-pontos-de-cultura'): BeneficiariosService
    {
        return new BeneficiariosService($this->planilha($resposta));
    }

    /** As linhas da página indexadas pelo número da inscrição; a ordem da grade é por número. */
    private function linhasPorNumero(Opportunity $raiz): array
    {
        $linhas = [];
        foreach ($this->service()->pagina($raiz, 0, 20)['linhas'] as $linha) {
            $linhas[$linha['number']] = $linha;
        }

        return $linhas;
    }

    function testPaginaTrazUmaLinhaPorContempladaComOTotal()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->contempladaComDocumento($raiz, $fase, '529.982.247-25');

        $pagina = $this->service()->pagina($raiz, 0, 20);

        $this->assertCount(2, $pagina['linhas']);
        $this->assertSame(2, $pagina['total']);
    }

    function testCpfVemDoCampoQueOGestorIndicouSemMascara()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $campo = $this->campoDeDocumento($raiz, $raiz, ['Pessoa Física']);
        $contemplada = $this->contemplada($raiz, $fase, []);
        $this->respondeFicha($raiz, (string) $contemplada->number, $campo, '111.444.777-35');

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('11144477735', $this->celula($linha, 'cpf'), 'o valor vem da ficha, sem máscara');
        $this->assertNull($this->celula($linha, 'cnpj'));
        $this->assertSame(LinhasBeneficiariosService::FONTE_FICHA, $linha['celulas']['cpf']['fonte']);
    }

    function testCadaTipoDeProponenteLeOCampoQueOGestorIndicouParaEle()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $campoDaPessoaFisica = $this->campoDaFicha($raiz, 'CPF do proponente');
        $campoDaPessoaJuridica = $this->campoDaFicha($raiz, 'CNPJ do proponente');
        $this->mapeiaDocumentos($raiz, [
            'Pessoa Física' => (int) $campoDaPessoaFisica->id,
            'Pessoa Jurídica' => (int) $campoDaPessoaJuridica->id,
        ]);

        $pessoaFisica = $this->contemplada($raiz, $fase, [], 'Pessoa Física');
        $pessoaJuridica = $this->contemplada($raiz, $fase, [], 'Pessoa Jurídica');

        // A pessoa física também respondeu o campo do CNPJ: é o tipo que decide qual dos dois vale.
        $this->respondeFicha($raiz, (string) $pessoaFisica->number, $campoDaPessoaFisica, '111.444.777-35');
        $this->respondeFicha($raiz, (string) $pessoaFisica->number, $campoDaPessoaJuridica, '11.222.333/0001-81');
        $this->respondeFicha($raiz, (string) $pessoaJuridica->number, $campoDaPessoaJuridica, '11.222.333/0001-81');

        $linhas = $this->linhasPorNumero($raiz);

        $this->assertSame('11144477735', $this->celula($linhas[(string) $pessoaFisica->number], 'cpf'));
        $this->assertNull($this->celula($linhas[(string) $pessoaFisica->number], 'cnpj'), 'o campo do outro tipo não entra na linha');
        $this->assertSame('11222333000181', $this->celula($linhas[(string) $pessoaJuridica->number], 'cnpj'));
    }

    public function testTipoDeProponenteEFaixaServemDeFonte(): void
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $this->mapeia($raiz, ['tipo_agente' => 'inscricao:tipo', 'cota' => 'inscricao:faixa']);
        $this->contemplada($raiz, $fase, [], 'MEI', 'Faixa rural');

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('MEI', $this->celula($linha, 'tipo_agente'));
        $this->assertSame('Faixa rural', $this->celula($linha, 'cota'));
        $this->assertSame(['tipo_agente', 'cota'], array_slice(array_keys(array_intersect_key($linha['pendencias'], ['tipo_agente' => 1, 'cota' => 1])), 0), 'nenhum dos dois é opção do modelo');
    }

    public function testEquivalenciaGravadaResolveALinha(): void
    {
        [$raiz, $fase] = $this->editalComCategorias();
        (new ResultadoDeParaService())->salvar($raiz, $this->planilha(), ['tipo_agente' => 'inscricao:tipo'], [
            'tipo_agente' => ['MEI' => 'Microempreendedor individual - MEI'],
        ]);
        $this->contemplada($raiz, $fase, [], 'MEI');

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('Microempreendedor individual - MEI', $this->celula($linha, 'tipo_agente'));
        $this->assertArrayNotHasKey('tipo_agente', $linha['pendencias']);
    }

    public function testDocumentoDeQuatorzeCaracteresServeSoAoCnpj(): void
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $campo = $this->campoDeDocumento($raiz, $raiz, ['Coletivo']);
        $contemplada = $this->contemplada($raiz, $fase, [], 'Coletivo');
        $this->respondeFicha($raiz, (string) $contemplada->number, $campo, '11.222.333/0001-81');

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('11222333000181', $this->celula($linha, 'cnpj'));
        $this->assertNull($this->celula($linha, 'cpf'));
    }

    function testTipoDeProponenteSemCampoIndicadoFicaSemDocumento()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $campo = $this->campoDeDocumento($raiz, $raiz, ['Pessoa Jurídica']);
        $contemplada = $this->contemplada($raiz, $fase, [], 'Pessoa Física');
        $this->respondeFicha($raiz, (string) $contemplada->number, $campo, '111.444.777-35');

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertNull($this->celula($linha, 'cpf'), 'o de/para é por tipo: o campo de outro tipo não vale');
        $this->assertNull($this->celula($linha, 'cnpj'));
        $this->assertArrayHasKey('cpf', $linha['pendencias'], 'sem documento a linha pende, em vez de sair com dado errado');
    }

    public function testCampoDeMultiplaEscolhaIndicadoComoDocumentoPende(): void
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $campo = $this->campoDaFicha($raiz, 'Entregas previstas', 'checkboxes');
        $this->mapeiaDocumentos($raiz, ['Pessoa Física' => (int) $campo->id]);
        $contemplada = $this->contemplada($raiz, $fase, []);
        $this->respondeFicha($raiz, (string) $contemplada->number, $campo, ['11144477735']);

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame(['11144477735'], $this->celula($linha, 'cpf'));
        $this->assertStringContainsString('mais de um valor', $linha['pendencias']['cpf']);
    }

    public function testRespostaSemTamanhoDeCpfNemDeCnpjApareceEPende(): void
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $campo = $this->campoDeDocumento($raiz, $raiz, ['Pessoa Física']);
        $contemplada = $this->contemplada($raiz, $fase, []);
        $this->respondeFicha($raiz, (string) $contemplada->number, $campo, '111.444.777');

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('111444777', $this->celula($linha, 'cpf'));
        $this->assertSame('CPF incompleto', $linha['pendencias']['cpf']);
        $this->assertSame('CNPJ incompleto', $linha['pendencias']['cnpj']);
    }

    public function testValorPorContempladoEOValorDaFaixaDivididoPelasVagas(): void
    {
        [$raiz, $fase] = $this->editalComCategorias([
            ['label' => 'faixa 1', 'limit' => 1, 'value' => 1000],
            ['label' => 'faixa 2', 'limit' => 40, 'value' => 60000],
        ]);
        $this->mapeia($raiz, ['valor_total_subsidio' => 'inscricao:valor']);
        $this->contempladaComDocumento($raiz, $fase, '111.444.777-35', 'Pessoa Física', 'faixa 2');

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('1500.00', $this->celula($linha, 'valor_total_subsidio'));
    }

    #[DataProvider('faixasSemValor')]
    public function testSemFaixaDoEditalOValorFicaPendente(string $faixa): void
    {
        [$raiz, $fase] = $this->editalComCategorias([['label' => 'faixa 1', 'limit' => 1, 'value' => 1000]]);
        $this->mapeia($raiz, ['valor_total_subsidio' => 'inscricao:valor']);
        $this->contempladaComDocumento($raiz, $fase, '111.444.777-35', 'Pessoa Física', $faixa);

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertNull($this->celula($linha, 'valor_total_subsidio'));
        $this->assertArrayHasKey('valor_total_subsidio', $linha['pendencias']);
        $this->assertArrayNotHasKey('cpf', $linha['pendencias']);
    }

    public static function faixasSemValor(): array
    {
        return ['sem faixa' => [''], 'faixa que saiu do edital' => ['faixa extinta']];
    }

    private function gravaLinha(Opportunity $raiz, Registration $contemplada, mixed $valor, string $chave = Controller::REGISTRATION_META_CULT_BR_RESULTADO_PLANILHA_LINHA): void
    {
        $conn = $this->app->em->getConnection();
        $origem = $this->app->repo('Registration')->findOneBy(['opportunity' => $raiz, 'number' => $contemplada->number]);

        $conn->executeStatement(
            "INSERT INTO registration_meta (id, object_id, key, value) VALUES (nextval('registration_meta_id_seq'::regclass), :id, :key, :value)",
            ['id' => (int) $origem->id, 'key' => $chave, 'value' => json_encode($valor)],
        );
        $this->app->em->refresh($origem);
    }

    public function testCelulaDoFormatoAntigoOuDeOutroModeloEIgnorada(): void
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $uma = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $outra = $this->contempladaComDocumento($raiz, $fase, '529.982.247-25');
        $this->gravaLinha($raiz, $uma, ['raca_etnia' => 'Preta', 'cpf' => '00000000000'], 'cultBrResultadoLinha');
        $this->gravaLinha($raiz, $outra, ['modelo' => 'premio_cultura_viva', 'celulas' => ['raca_etnia' => 'Preta']]);

        foreach ($this->linhasPorNumero($raiz) as $linha) {
            $this->assertNull($this->celula($linha, 'raca_etnia'));
        }

        $this->service()->salvarCelulas($raiz, (int) $uma->id, ['raca_etnia' => 'Parda']);

        $antigo = $this->app->em->getConnection()->fetchOne("SELECT value FROM registration_meta WHERE key = 'cultBrResultadoLinha' ORDER BY id DESC LIMIT 1");
        $this->assertSame(['raca_etnia' => 'Preta', 'cpf' => '00000000000'], json_decode($antigo, true), 'o metadado antigo segue como estava');
    }

    public function testCelulasDigitadasSaoDoModeloEmQueForamDigitadas(): void
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');

        $this->service()->salvarCelulas($raiz, (int) $contemplada->id, ['raca_etnia' => 'Preta', 'cota' => 'Outra']);
        $linha = $this->service()->salvarCelulas($raiz, (int) $contemplada->id, ['raca_etnia' => 'Parda']);

        $this->assertSame('Parda', $this->celula($linha, 'raca_etnia'));
        $this->assertSame('Outra', $this->celula($linha, 'cota'), 'gravar uma célula não apaga as outras');
        $this->assertNull($this->celula($this->service('modelo-premio-cultura-viva')->pagina($raiz, 0, 20)['linhas'][0], 'raca_etnia'));
    }

    public function testCelulaForaDoModeloOuComVariosValoresNaoEGravada(): void
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');

        $this->service()->salvarCelulas($raiz, (int) $contemplada->id, ['raca_etnia' => ['Preta'], 'data_recebimento_recurso' => '10/01/2026', 'cota' => 'Outra']);

        $gravado = $this->app->em->getConnection()->fetchOne(
            'SELECT value FROM registration_meta WHERE key = :key ORDER BY id DESC LIMIT 1',
            ['key' => Controller::REGISTRATION_META_CULT_BR_RESULTADO_PLANILHA_LINHA],
        );
        $this->assertSame(['modelo' => 'pontos_de_cultura', 'celulas' => ['cota' => 'Outra']], json_decode($gravado, true));
    }

    public function testOQueOGestorDigitouVenceODadoDaInscricao(): void
    {
        [$raiz, $fase] = $this->editalComCategorias([['label' => 'faixa 1', 'limit' => 1, 'value' => 1000]]);
        $this->mapeia($raiz, ['valor_total_subsidio' => 'inscricao:valor']);
        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35', 'Pessoa Física', 'faixa 1');

        $linha = $this->service()->salvarCelulas($raiz, (int) $contemplada->id, ['valor_total_subsidio' => '777.00']);

        $this->assertSame('777.00', $this->celula($linha, 'valor_total_subsidio'));
        $this->assertSame(LinhasBeneficiariosService::FONTE_GESTOR, $linha['celulas']['valor_total_subsidio']['fonte']);
    }

    public function testCelulaNulaVoltaAoValorDaFicha(): void
    {
        [$raiz, $fase] = $this->editalComCategorias([['label' => 'faixa 1', 'limit' => 1, 'value' => 1000]]);
        $this->mapeia($raiz, ['valor_total_subsidio' => 'inscricao:valor']);
        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35', 'Pessoa Física', 'faixa 1');
        $this->service()->salvarCelulas($raiz, (int) $contemplada->id, ['valor_total_subsidio' => '777.00', 'cota' => 'Outra']);

        $linha = $this->service()->salvarCelulas($raiz, (int) $contemplada->id, ['valor_total_subsidio' => null]);

        $this->assertSame('1000.00', $this->celula($linha, 'valor_total_subsidio'), 'apagar a célula devolve o valor da ficha');
        $this->assertSame(LinhasBeneficiariosService::FONTE_FICHA, $linha['celulas']['valor_total_subsidio']['fonte']);
        $this->assertSame('Outra', $this->celula($linha, 'cota'), 'as outras células do gestor ficam');
    }

    public function testCelulaComTextoVazioSegueEmBranco(): void
    {
        [$raiz, $fase] = $this->editalComCategorias([['label' => 'faixa 1', 'limit' => 1, 'value' => 1000]]);
        $this->mapeia($raiz, ['valor_total_subsidio' => 'inscricao:valor']);
        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35', 'Pessoa Física', 'faixa 1');

        $linha = $this->service()->salvarCelulas($raiz, (int) $contemplada->id, ['valor_total_subsidio' => '']);

        $this->assertNull($this->celula($linha, 'valor_total_subsidio'));
        $this->assertSame(LinhasBeneficiariosService::FONTE_GESTOR, $linha['celulas']['valor_total_subsidio']['fonte']);
    }

    function testPaginaRespeitaORecorte()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        foreach (['111.444.777-35', '529.982.247-25', '007.898.359-24'] as $cpf) {
            $this->contempladaComDocumento($raiz, $fase, $cpf);
        }

        $service = $this->service();
        $primeira = $service->pagina($raiz, 0, 2);
        $segunda = $service->pagina($raiz, 2, 2);

        $this->assertCount(2, $primeira['linhas']);
        $this->assertCount(1, $segunda['linhas']);
        $this->assertSame(3, $segunda['total'], 'o total é do edital, não da página');
        $this->assertNotSame($primeira['linhas'][0]['number'], $segunda['linhas'][0]['number']);
    }

    function testNaoContaInscricaoQueNaoFoiContemplada()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');

        $this->app->disableAccessControl();
        $naoSelecionada = new Registration();
        $naoSelecionada->opportunity = $fase;
        $naoSelecionada->owner = $raiz->owner;
        $naoSelecionada->save(true);
        $naoSelecionada->status = Registration::STATUS_NOTAPPROVED;
        $naoSelecionada->save(true);
        $this->app->enableAccessControl();

        $pagina = $this->service()->pagina($raiz, 0, 20);

        $this->assertSame(1, $pagina['total']);
    }

    function testColunaMapeadaSaiDaFichaDaInscricao()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $campo = $this->campoDaFicha($raiz, 'CEP do espaço');
        $this->mapeia($raiz, ['cep_espaco_cultural' => "campo:{$campo->id}"]);

        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->respondeFicha($raiz, (string) $contemplada->number, $campo, '57072-900');

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('57072-900', $this->celula($linha, 'cep_espaco_cultural'));
        $this->assertSame(LinhasBeneficiariosService::FONTE_FICHA, $linha['celulas']['cep_espaco_cultural']['fonte']);
    }

    function testFichaDeOutraFaseTambemEhLida()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $campo = $this->campoDaFicha($fase, 'Tipo de espaço');
        $this->mapeia($raiz, ['tipo_espaco_cultural' => "campo:{$campo->id}"]);

        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->respondeFicha($fase, (string) $contemplada->number, $campo, 'Cinema');

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('Cinema', $this->celula($linha, 'tipo_espaco_cultural'), 'o campo mapeado pode estar em qualquer fase do edital');
    }

    function testCadaColunaLeOCampoDaSuaPropriaFase()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $cep = $this->campoDaFicha($raiz, 'CEP do espaço');
        $formato = $this->campoDaFicha($fase, 'Tipo de espaço');
        $this->mapeia($raiz, ['cep_espaco_cultural' => "campo:{$cep->id}", 'tipo_espaco_cultural' => "campo:{$formato->id}"]);

        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->respondeFicha($raiz, (string) $contemplada->number, $cep, '57072-900');
        $this->respondeFicha($fase, (string) $contemplada->number, $formato, 'Cinema');

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('57072-900', $this->celula($linha, 'cep_espaco_cultural'), 'o CEP está na ficha da primeira fase');
        $this->assertSame('Cinema', $this->celula($linha, 'tipo_espaco_cultural'), 'o tipo está na ficha da fase final');
    }

    function testCampoApagadoDepoisDoMapeamentoNaoQuebraAGrade()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $campo = $this->campoDaFicha($raiz, 'CEP do espaço');
        $this->mapeia($raiz, ['cep_espaco_cultural' => "campo:{$campo->id}"]);

        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->respondeFicha($raiz, (string) $contemplada->number, $campo, '57072-900');

        $this->app->disableAccessControl();
        $campo->delete(true);
        $this->app->enableAccessControl();

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertNull($this->celula($linha, 'cep_espaco_cultural'), 'coluna mapeada para campo que não existe mais fica vazia, sem derrubar a página');
    }

    function testOQueOGestorDigitouVenceAFicha()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $campo = $this->campoDaFicha($raiz, 'CEP do espaço');
        $this->mapeia($raiz, ['cep_espaco_cultural' => "campo:{$campo->id}"]);

        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->respondeFicha($raiz, (string) $contemplada->number, $campo, '57072-900');

        $this->service()->salvarCelulas($raiz, (int) $contemplada->id, ['cep_espaco_cultural' => '70000-000']);

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('70000-000', $this->celula($linha, 'cep_espaco_cultural'));
        $this->assertSame(LinhasBeneficiariosService::FONTE_GESTOR, $linha['celulas']['cep_espaco_cultural']['fonte']);
    }

    function testContempladaSemInscricaoNaPrimeiraFaseUsaOProprioCadastro()
    {
        [$raiz, $fase] = $this->editalComCategorias();

        $campo = $this->campoDeDocumento($raiz, $fase, ['Pessoa Física']);

        $this->app->disableAccessControl();
        $soNaFaseFinal = $this->inscricao($fase, [], 'Pessoa Física', '');
        $this->mudaStatus($soNaFaseFinal, Registration::STATUS_APPROVED);
        $this->app->enableAccessControl();

        $this->respondeFicha($fase, (string) $soNaFaseFinal->number, $campo, '111.444.777-35');

        $service = $this->service();
        $linha = $service->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('11144477735', $this->celula($linha, 'cpf'), 'o campo indicado na própria fase final responde quando não há inscrição na primeira');
        $this->assertSame((int) $soNaFaseFinal->id, $linha['origemId']);

        $salva = $service->salvarCelulas($raiz, (int) $soNaFaseFinal->id, ['raca_etnia' => 'Parda']);
        $this->assertSame('Parda', $this->celula($salva, 'raca_etnia'), 'e a célula digitada tem onde ser gravada');
        $this->assertSame('Parda', $this->celula($service->pagina($raiz, 0, 20)['linhas'][0], 'raca_etnia'));
    }

    function testFaseFinalNaoPublicadaNaoTemBeneficiarios()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Sem Publicacao ' . uniqid());
        $raiz = $this->raizElegivel($owner, $subsite);
        $fase = $this->faseFinal($raiz, publicada: false);
        $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');

        $pagina = $this->service()->pagina($raiz, 0, 20);

        $this->assertSame([], $pagina['linhas'], 'sem resultado publicado não há beneficiário a declarar');
        $this->assertSame(0, $pagina['total']);
        $this->assertNull($this->service()->faseFinalPublicada($raiz));
    }

    function testServicoRegistraOsCamposDaFichaAntesDeLer()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $campo = $this->campoDaFicha($raiz, 'CEP do espaço');
        $this->mapeia($raiz, ['cep_espaco_cultural' => "campo:{$campo->id}"]);

        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->respondeFicha($raiz, (string) $contemplada->number, $campo, '57072-900');

        // Numa requisição nova o campo não está registrado; criá-lo na fixture registra, e isso esconderia o passo.
        $this->app->unregisterEntityMetadata(Registration::class, $campo->fieldName);

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('57072-900', $this->celula($linha, 'cep_espaco_cultural'));
    }

    public function testCampoDeMultiplaEscolhaPende(): void
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $campo = $this->campoDaFicha($raiz, 'Deficiências', 'checkboxes');
        $this->mapeia($raiz, ['pessoa_com_deficiencia' => "campo:{$campo->id}"]);

        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->respondeFicha($raiz, (string) $contemplada->number, $campo, ['Sim, auditiva', 'Sim, visual']);

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame(['Sim, auditiva', 'Sim, visual'], $this->celula($linha, 'pessoa_com_deficiencia'));
        $this->assertStringContainsString('mais de um valor', $linha['pendencias']['pessoa_com_deficiencia']);
    }

    public function testCampoDeDadosCompostosPende(): void
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $campo = $this->campoDaFicha($raiz, 'Dados bancários', 'bankFields');
        $this->mapeia($raiz, ['raca_etnia' => "campo:{$campo->id}"]);

        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->respondeFicha($raiz, (string) $contemplada->number, $campo, '{"branch": "1234"}');

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertIsObject($this->celula($linha, 'raca_etnia'));
        $this->assertStringContainsString('mais de um valor', $linha['pendencias']['raca_etnia']);
    }

    public function testCampoDeMoedaChegaComoValorDoContrato(): void
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $campo = $this->campoDaFicha($raiz, 'Valor do subsídio', 'currency');
        $this->mapeia($raiz, ['valor_total_subsidio' => "campo:{$campo->id}"]);

        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->respondeFicha($raiz, (string) $contemplada->number, $campo, '15000.5');

        $this->assertSame('15000.50', $this->celula($this->service()->pagina($raiz, 0, 20)['linhas'][0], 'valor_total_subsidio'));
    }

    function testVarreduraEmLotesNaoAcumulaAsInscricoesJaProcessadas()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        foreach (['111.444.777-35', '529.982.247-25', '007.898.359-24'] as $cpf) {
            $this->contempladaComDocumento($raiz, $fase, $cpf);
        }

        $lotes = 0;
        $this->service()->emLotes($raiz, 1, function () use (&$lotes) {
            $lotes++;
        });

        $hidratadas = $this->app->em->getUnitOfWork()->getIdentityMap()[Registration::class] ?? [];

        $this->assertSame(3, $lotes, 'cada contemplada veio no seu lote');
        $this->assertCount(0, $hidratadas, 'segurar as inscrições já processadas estoura a memória do edital grande');
    }

    function testLinhasDeTrazSomenteAsContempladasInformadas()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $primeira = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->contempladaComDocumento($raiz, $fase, '529.982.247-25');

        $linhas = $this->service()->linhasDe($raiz, [(int) $primeira->id]);

        $this->assertCount(1, $linhas);
        $this->assertSame((int) $primeira->id, $linhas[0]['registrationId']);
    }

    function testLinhasDeRespeitaAOrdemRecebida()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $primeira = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $segunda = $this->contempladaComDocumento($raiz, $fase, '529.982.247-25');

        $linhas = $this->service()->linhasDe($raiz, [(int) $segunda->id, (int) $primeira->id]);

        $ordem = array_column($linhas, 'registrationId');
        $this->assertSame([(int) $segunda->id, (int) $primeira->id], $ordem, 'a tabela ordena, e as células precisam casar com a linha que ela desenhou');
    }

    function testLinhasDeIgnoraInscricaoQueNaoEstaContempladaNaFaseFinal()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $outra = $this->contempladaComDocumento($raiz, $fase, '529.982.247-25');
        $this->mudaStatus($outra, Registration::STATUS_NOTAPPROVED);

        $linhas = $this->service()->linhasDe($raiz, [(int) $contemplada->id, (int) $outra->id]);

        $this->assertCount(1, $linhas);
        $this->assertSame((int) $contemplada->id, $linhas[0]['registrationId']);
    }

    function testLinhasDeSemIdentificadoresVoltaVazio()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');

        $this->assertSame([], $this->service()->linhasDe($raiz, []));
    }

    #[DataProvider('cnpjsAlfanumericos')]
    public function testCnpjAlfanumericoServeSoAoCnpjESemPerderCaractere(string $informado, string $esperado): void
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $campo = $this->campoDeDocumento($raiz, $raiz, ['Coletivo']);
        $contemplada = $this->contemplada($raiz, $fase, [], 'Coletivo');
        $this->respondeFicha($raiz, (string) $contemplada->number, $campo, $informado);

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame($esperado, $this->celula($linha, 'cnpj'));
        $this->assertNull($this->celula($linha, 'cpf'), 'nem quando ele tem onze dígitos');
    }

    public static function cnpjsAlfanumericos(): array
    {
        return ['nove dígitos' => ['12.abc.345/01de-35', '12ABC34501DE35'], 'onze dígitos' => ['12.abc.345/0101-35', '12ABC345010135']];
    }
}
