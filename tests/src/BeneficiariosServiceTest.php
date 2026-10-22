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

    function testPaginaTrazUmaLinhaPorContempladaComOTotal()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '111.444.777-35']]);
        $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '529.982.247-25']]);

        $pagina = $this->service()->pagina($raiz, 0, 20);

        $this->assertCount(2, $pagina['linhas']);
        $this->assertSame(2, $pagina['total']);
    }

    function testCpfVemDoSnapshotDaInscricaoDeOrigemSemMascara()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '111.444.777-35']]);

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('11144477735', $this->celula($linha, 'cpf_responsavel_execucao'), 'a fase final não tem snapshot; o valor vem da origem, sem máscara');
        $this->assertNull($this->celula($linha, 'cnpj_responsavel_execucao'));
        $this->assertSame(LinhasBeneficiariosService::FONTE_AUTOMATICA, $linha['celulas']['cpf_responsavel_execucao']['fonte']);
    }

    function testPessoaJuridicaRespondePeloAgenteDoColetivo()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $this->contemplada($raiz, $fase, [
            'owner' => ['cpf' => '111.444.777-35'],
            'coletivo' => ['cnpj' => '11.222.333/0001-81'],
        ], 'Pessoa Jurídica');

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('11222333000181', $this->celula($linha, 'cnpj_responsavel_execucao'));
        $this->assertNull($this->celula($linha, 'cpf_responsavel_execucao'), 'o CPF do representante não responde pela execução da pessoa jurídica');
        $this->assertSame('Pessoa jurídica', $this->celula($linha, 'tipo_agente'), 'o CultBr só conhece as duas naturezas, e com esta grafia');
    }

    function testTipoDeAgenteSaiDoDocumentoENaoDoTipoDeProponenteDoEdital()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '111.444.777-35']], 'MEI');

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('Pessoa física', $this->celula($linha, 'tipo_agente'), 'MEI não existe no vocabulário do CultBr');
    }

    function testDocumentoGenericoEClassificadoPelaQuantidadeDeDigitos()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $this->contemplada($raiz, $fase, ['owner' => ['documento' => '007.898.359-24']]);

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('00789835924', $this->celula($linha, 'cpf_responsavel_execucao'));
        $this->assertNull($this->celula($linha, 'cnpj_responsavel_execucao'), 'onze dígitos não servem como CNPJ');
    }

    function testPessoaJuridicaSemAgenteDoColetivoFicaSemDocumento()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '111.444.777-35']], 'Pessoa Jurídica');

        $service = $this->service();
        $linha = $service->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertNull($this->celula($linha, 'cnpj_responsavel_execucao'));
        $this->assertNull($this->celula($linha, 'cpf_responsavel_execucao'), 'o CPF do representante não pode virar o responsável da pessoa jurídica');
        $this->assertContains(LinhasBeneficiariosService::PENDENCIA_RESPONSAVEL, $linha['pendencias'], 'sem documento a linha pende, em vez de sair com dado errado');
    }

    function testDocumentoGenericoDeQuatorzeDigitosEhCnpj()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $this->contemplada($raiz, $fase, ['coletivo' => ['documento' => '11.222.333/0001-81']], 'Coletivo');

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('11222333000181', $this->celula($linha, 'cnpj_responsavel_execucao'));
        $this->assertNull($this->celula($linha, 'cpf_responsavel_execucao'), 'quatorze dígitos não servem como CPF');
    }

    function testIdentificadorDoEditalEONomeDaRaiz()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '111.444.777-35']]);

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame($raiz->name, $this->celula($linha, 'identificador_edital'));
    }

    function testValorSaiDaCategoriaDaInscricao()
    {
        [$raiz, $fase] = $this->editalComCategorias([
            ['label' => 'faixa 1', 'value' => 1000],
            ['label' => 'faixa 2', 'value' => 5000],
        ]);
        $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '111.444.777-35']], 'Pessoa Física', 'faixa 2');

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('5000.00', $this->celula($linha, 'valor_recebido_pnab'), 'o contrato pede o valor como string com duas casas');
    }

    function testSemCategoriaOValorFicaPendente()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '111.444.777-35']]);

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertNull($this->celula($linha, 'valor_recebido_pnab'));
        $this->assertContains('valor_recebido_pnab', $linha['pendencias']);
        $this->assertContains('data_recebimento_recurso', $linha['pendencias']);
        $this->assertNotContains(LinhasBeneficiariosService::PENDENCIA_RESPONSAVEL, $linha['pendencias'], 'o CPF do snapshot já satisfaz o responsável');
    }

    function testOQueOGestorDigitouVenceOCalculado()
    {
        [$raiz, $fase] = $this->editalComCategorias([['label' => 'faixa 1', 'value' => 1000]]);
        $contemplada = $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '111.444.777-35']], 'Pessoa Física', 'faixa 1');

        $this->app->disableAccessControl();
        $origem = $this->app->repo('Registration')->findOneBy(['opportunity' => $raiz, 'number' => $contemplada->number]);
        $origem->{Controller::REGISTRATION_META_CULT_BR_RESULTADO_LINHA} = ['valor_recebido_pnab' => '777.00', 'data_recebimento_recurso' => '10/01/2026'];
        $origem->saveMetadata(true);
        $this->app->enableAccessControl();

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('777.00', $this->celula($linha, 'valor_recebido_pnab'));
        $this->assertSame(LinhasBeneficiariosService::FONTE_GESTOR, $linha['celulas']['valor_recebido_pnab']['fonte']);
        $this->assertSame([], $linha['pendencias'], 'com a data informada e o responsável no snapshot, a linha fica íntegra');
    }

    function testPaginaRespeitaORecorte()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        foreach (['111.444.777-35', '529.982.247-25', '007.898.359-24'] as $cpf) {
            $this->contemplada($raiz, $fase, ['owner' => ['cpf' => $cpf]]);
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
        $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '111.444.777-35']]);

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

        $contemplada = $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '111.444.777-35']]);
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

        $contemplada = $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '111.444.777-35']]);
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

        $contemplada = $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '111.444.777-35']]);
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

        $contemplada = $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '111.444.777-35']]);
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

        $contemplada = $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '111.444.777-35']]);
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

        $this->app->disableAccessControl();
        $soNaFaseFinal = $this->inscricao($fase, ['owner' => ['cpf' => '111.444.777-35']], 'Pessoa Física', '');
        $this->mudaStatus($soNaFaseFinal, Registration::STATUS_APPROVED);
        $this->app->enableAccessControl();

        $service = $this->service();
        $linha = $service->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame('11144477735', $this->celula($linha, 'cpf_responsavel_execucao'), 'sem inscrição na primeira fase, o cadastro vem da própria contemplada');
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
        $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '111.444.777-35']]);

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

        $contemplada = $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '111.444.777-35']]);
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

        $contemplada = $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '111.444.777-35']]);
        $this->respondeFicha($raiz, (string) $contemplada->number, $campo, ['Sim - Governo Estadual', 'Sim - Setor Privado']);

        $linha = $this->service()->pagina($raiz, 0, 20)['linhas'][0];

        $this->assertSame(['Sim - Governo Estadual', 'Sim - Setor Privado'], $this->celula($linha, 'recebeu_recursos_outras_fontes'), 'o contrato pede lista, e quem converte é a definição do metadado registrado');
    }

    function testVarreduraEmLotesNaoAcumulaAsInscricoesJaProcessadas()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        foreach (['111.444.777-35', '529.982.247-25', '007.898.359-24'] as $cpf) {
            $this->contemplada($raiz, $fase, ['owner' => ['cpf' => $cpf]]);
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
        $primeira = $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '111.444.777-35']]);
        $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '529.982.247-25']]);

        $linhas = $this->service()->linhasDe($raiz, [(int) $primeira->id]);

        $this->assertCount(1, $linhas);
        $this->assertSame((int) $primeira->id, $linhas[0]['registrationId']);
    }

    function testLinhasDeRespeitaAOrdemRecebida()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $primeira = $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '111.444.777-35']]);
        $segunda = $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '529.982.247-25']]);

        $linhas = $this->service()->linhasDe($raiz, [(int) $segunda->id, (int) $primeira->id]);

        $ordem = array_column($linhas, 'registrationId');
        $this->assertSame([(int) $segunda->id, (int) $primeira->id], $ordem, 'a tabela ordena, e as células precisam casar com a linha que ela desenhou');
    }

    function testLinhasDeIgnoraInscricaoQueNaoEstaContempladaNaFaseFinal()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $contemplada = $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '111.444.777-35']]);
        $outra = $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '529.982.247-25']]);
        $this->mudaStatus($outra, Registration::STATUS_NOTAPPROVED);

        $linhas = $this->service()->linhasDe($raiz, [(int) $contemplada->id, (int) $outra->id]);

        $this->assertCount(1, $linhas);
        $this->assertSame((int) $contemplada->id, $linhas[0]['registrationId']);
    }

    function testLinhasDeSemIdentificadoresVoltaVazio()
    {
        [$raiz, $fase] = $this->editalComCategorias();
        $this->contemplada($raiz, $fase, ['owner' => ['cpf' => '111.444.777-35']]);

        $this->assertSame([], $this->service()->linhasDe($raiz, []));
    }
}
