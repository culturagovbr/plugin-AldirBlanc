<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Services\BeneficiariosService;
use AldirBlanc\Services\LinhasBeneficiariosService;
use AldirBlanc\Services\ResultadoDeParaService;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
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

    private function service(): BeneficiariosService
    {
        return new BeneficiariosService();
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

        $this->assertSame('11144477735', $this->celula($linha, 'cpf_responsavel_execucao'), 'o valor vem da ficha, sem máscara');
        $this->assertNull($this->celula($linha, 'cnpj_responsavel_execucao'));
        $this->assertSame(LinhasBeneficiariosService::FONTE_AUTOMATICA, $linha['celulas']['cpf_responsavel_execucao']['fonte']);
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

        $this->assertSame('11144477735', $this->celula($linhas[(string) $pessoaFisica->number], 'cpf_responsavel_execucao'));
        $this->assertNull($this->celula($linhas[(string) $pessoaFisica->number], 'cnpj_responsavel_execucao'), 'o campo do outro tipo não entra na linha');
        $this->assertSame('11222333000181', $this->celula($linhas[(string) $pessoaJuridica->number], 'cnpj_responsavel_execucao'));
    }

    function testTipoDeAgenteSaiDoDocumentoENaoDoTipoDeProponenteDoEdital()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $campo = $this->campoDeDocumento($raiz, $raiz, ['MEI']);
        $contemplada = $this->contemplada($raiz, $fase, [], 'MEI');
        $this->respondeFicha($raiz, (string) $contemplada->number, $campo, '111.444.777-35');

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('Pessoa física', $this->celula($linha, 'tipo_agente'), 'MEI não existe no vocabulário do CultBr');
    }

    function testDocumentoDeQuatorzeDigitosEhCnpjEDecideOTipoDeAgente()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $campo = $this->campoDeDocumento($raiz, $raiz, ['Coletivo']);
        $contemplada = $this->contemplada($raiz, $fase, [], 'Coletivo');
        $this->respondeFicha($raiz, (string) $contemplada->number, $campo, '11.222.333/0001-81');

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('11222333000181', $this->celula($linha, 'cnpj_responsavel_execucao'));
        $this->assertNull($this->celula($linha, 'cpf_responsavel_execucao'), 'quatorze dígitos não servem como CPF');
        $this->assertSame('Pessoa jurídica', $this->celula($linha, 'tipo_agente'), 'o CultBr só conhece as duas naturezas, e com esta grafia');
    }

    function testTipoDeProponenteSemCampoIndicadoFicaSemDocumento()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $campo = $this->campoDeDocumento($raiz, $raiz, ['Pessoa Jurídica']);
        $contemplada = $this->contemplada($raiz, $fase, [], 'Pessoa Física');
        $this->respondeFicha($raiz, (string) $contemplada->number, $campo, '111.444.777-35');

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertNull($this->celula($linha, 'cpf_responsavel_execucao'), 'o de/para é por tipo: o campo de outro tipo não vale');
        $this->assertNull($this->celula($linha, 'cnpj_responsavel_execucao'));
        $this->assertContains(LinhasBeneficiariosService::PENDENCIA_RESPONSAVEL, $linha['pendencias'], 'sem documento a linha pende, em vez de sair com dado errado');
    }

    function testCampoDeMultiplaEscolhaIndicadoNaoViraDocumento()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $campo = $this->campoDeDocumento($raiz, $raiz, ['Pessoa Física'], 'Entregas previstas');
        $contemplada = $this->contemplada($raiz, $fase, []);
        $this->respondeFicha($raiz, (string) $contemplada->number, $campo, ['11144477735', '52998224725']);

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertNull($this->celula($linha, 'cpf_responsavel_execucao'), 'valor que não é escalar não vira documento');
        $this->assertNull($this->celula($linha, 'cnpj_responsavel_execucao'));
    }

    function testRespostaSemTamanhoDeCpfNemDeCnpjNaoViraDocumento()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $campo = $this->campoDeDocumento($raiz, $raiz, ['Pessoa Física']);
        $contemplada = $this->contemplada($raiz, $fase, []);
        $this->respondeFicha($raiz, (string) $contemplada->number, $campo, 'a combinar');

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertNull($this->celula($linha, 'cpf_responsavel_execucao'));
        $this->assertNull($this->celula($linha, 'cnpj_responsavel_execucao'));
        $this->assertNull($this->celula($linha, 'tipo_agente'));
    }

    function testIdentificadorDoEditalEONomeDaRaiz()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame($raiz->name, $this->celula($linha, 'identificador_edital'));
    }

    function testValorSaiDaCategoriaDaInscricao()
    {
        [$raiz, $fase] = $this->editalComCategorias([
            ['label' => 'faixa 1', 'value' => 1000],
            ['label' => 'faixa 2', 'value' => 5000],
        ]);
        $this->contempladaComDocumento($raiz, $fase, '111.444.777-35', 'Pessoa Física', 'faixa 2');

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('5000.00', $this->celula($linha, 'valor_recebido_pnab'), 'o contrato pede o valor como string com duas casas');
    }

    function testSemCategoriaOValorFicaPendente()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertNull($this->celula($linha, 'valor_recebido_pnab'));
        $this->assertContains('valor_recebido_pnab', $linha['pendencias']);
        $this->assertContains('data_recebimento_recurso', $linha['pendencias']);
        $this->assertNotContains(LinhasBeneficiariosService::PENDENCIA_RESPONSAVEL, $linha['pendencias'], 'o documento da ficha já satisfaz o responsável');
    }

    function testOQueOGestorDigitouVenceOCalculado()
    {
        [$raiz, $fase] = $this->editalComCategorias([['label' => 'faixa 1', 'value' => 1000]]);
        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35', 'Pessoa Física', 'faixa 1');

        $this->app->disableAccessControl();
        $origem = $this->app->repo('Registration')->findOneBy(['opportunity' => $raiz, 'number' => $contemplada->number]);
        $origem->{Controller::REGISTRATION_META_CULT_BR_RESULTADO_LINHA} = ['valor_recebido_pnab' => '777.00', 'data_recebimento_recurso' => '10/01/2026'];
        $origem->saveMetadata(true);
        $this->app->enableAccessControl();

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('777.00', $this->celula($linha, 'valor_recebido_pnab'));
        $this->assertSame(LinhasBeneficiariosService::FONTE_GESTOR, $linha['celulas']['valor_recebido_pnab']['fonte']);
        $this->assertSame([], $linha['pendencias'], 'com a data informada e o documento na ficha, a linha fica íntegra');
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
        $campo = $this->campoDaFicha($raiz, 'CEP do local de execução');
        (new ResultadoDeParaService())->salvarMapeamento($raiz, ['cep_local_execucao' => $campo->id]);

        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->respondeFicha($raiz, (string) $contemplada->number, $campo, '57072-900');

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('57072-900', $this->celula($linha, 'cep_local_execucao'));
        $this->assertSame(LinhasBeneficiariosService::FONTE_FICHA, $linha['celulas']['cep_local_execucao']['fonte']);
    }

    function testFichaDeOutraFaseTambemEhLida()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $campo = $this->campoDaFicha($fase, 'Formato de execução');
        (new ResultadoDeParaService())->salvarMapeamento($raiz, ['formato_execucao' => $campo->id]);

        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->respondeFicha($fase, (string) $contemplada->number, $campo, 'Presencial Fixo');

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('Presencial Fixo', $this->celula($linha, 'formato_execucao'), 'o campo mapeado pode estar em qualquer fase do edital');
    }

    function testCadaColunaLeOCampoDaSuaPropriaFase()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $cep = $this->campoDaFicha($raiz, 'CEP do local de execução');
        $formato = $this->campoDaFicha($fase, 'Formato de execução');
        (new ResultadoDeParaService())->salvarMapeamento($raiz, ['cep_local_execucao' => $cep->id, 'formato_execucao' => $formato->id]);

        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->respondeFicha($raiz, (string) $contemplada->number, $cep, '57072-900');
        $this->respondeFicha($fase, (string) $contemplada->number, $formato, 'Presencial Fixo');

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('57072-900', $this->celula($linha, 'cep_local_execucao'), 'o CEP está na ficha da primeira fase');
        $this->assertSame('Presencial Fixo', $this->celula($linha, 'formato_execucao'), 'o formato está na ficha da fase final');
    }

    function testCampoApagadoDepoisDoMapeamentoNaoQuebraAGrade()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $campo = $this->campoDaFicha($raiz, 'CEP do local de execução');
        (new ResultadoDeParaService())->salvarMapeamento($raiz, ['cep_local_execucao' => $campo->id]);

        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->respondeFicha($raiz, (string) $contemplada->number, $campo, '57072-900');

        $this->app->disableAccessControl();
        $campo->delete(true);
        $this->app->enableAccessControl();

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertNull($this->celula($linha, 'cep_local_execucao'), 'coluna mapeada para campo que não existe mais fica vazia, sem derrubar a página');
    }

    function testOQueOGestorDigitouVenceAFicha()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $campo = $this->campoDaFicha($raiz, 'CEP do local de execução');
        (new ResultadoDeParaService())->salvarMapeamento($raiz, ['cep_local_execucao' => $campo->id]);

        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->respondeFicha($raiz, (string) $contemplada->number, $campo, '57072-900');

        $this->app->disableAccessControl();
        $origem = $this->app->repo('Registration')->findOneBy(['opportunity' => $raiz, 'number' => $contemplada->number]);
        $origem->{Controller::REGISTRATION_META_CULT_BR_RESULTADO_LINHA} = ['cep_local_execucao' => '70000-000'];
        $origem->saveMetadata(true);
        $this->app->enableAccessControl();

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('70000-000', $this->celula($linha, 'cep_local_execucao'));
        $this->assertSame(LinhasBeneficiariosService::FONTE_GESTOR, $linha['celulas']['cep_local_execucao']['fonte']);
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

        $this->assertSame('11144477735', $this->celula($linha, 'cpf_responsavel_execucao'), 'o campo indicado na própria fase final responde quando não há inscrição na primeira');
        $this->assertSame((int) $soNaFaseFinal->id, $linha['origemId']);

        $salva = $service->salvarCelulas($raiz, (int) $soNaFaseFinal->id, ['data_recebimento_recurso' => '10/01/2026']);
        $this->assertSame('10/01/2026', $salva['linha']['celulas']['data_recebimento_recurso']['valor'], 'e a célula digitada tem onde ser gravada');
        $this->assertSame('10/01/2026', $service->pagina($raiz, 0, 20)['linhas'][0]['celulas']['data_recebimento_recurso']['valor']);
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
        $campo = $this->campoDaFicha($raiz, 'CEP do local de execução');
        (new ResultadoDeParaService())->salvarMapeamento($raiz, ['cep_local_execucao' => $campo->id]);

        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->respondeFicha($raiz, (string) $contemplada->number, $campo, '57072-900');

        // Numa requisição nova o campo não está registrado; criá-lo na fixture registra, e isso esconderia o passo.
        $this->app->unregisterEntityMetadata(Registration::class, $campo->fieldName);

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('57072-900', $this->celula($linha, 'cep_local_execucao'));
    }

    function testCampoDeMultiplaEscolhaChegaComoLista()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $campo = $this->campoDaFicha($raiz, 'Recebeu recursos de outras fontes?', 'checkboxes');
        (new ResultadoDeParaService())->salvarMapeamento($raiz, ['recebeu_recursos_outras_fontes' => $campo->id]);

        $contemplada = $this->contempladaComDocumento($raiz, $fase, '111.444.777-35');
        $this->respondeFicha($raiz, (string) $contemplada->number, $campo, ['Sim - Governo Estadual', 'Sim - Setor Privado']);

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame(['Sim - Governo Estadual', 'Sim - Setor Privado'], $this->celula($linha, 'recebeu_recursos_outras_fontes'), 'o contrato pede lista, e quem converte é a definição do metadado registrado');
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
}
