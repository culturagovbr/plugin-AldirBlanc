<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Services\LinhasBeneficiariosService;
use AldirBlanc\Services\ResultadoDeParaService;
use Tests\Abstract\TestCase;

/** Linha da planilha de beneficiários: precedência entre as três fontes e o que falta para o validador aceitar. */
class LinhasBeneficiariosServiceTest extends TestCase
{
    private function service(): LinhasBeneficiariosService
    {
        return new LinhasBeneficiariosService();
    }

    /** Linha que o validador aceita: os três obrigatórios mais o documento do responsável. */
    private function linhaMinima(): array
    {
        return ['identificador_edital' => 'Edital X', 'valor_recebido_pnab' => '15000.00', 'cpf_responsavel_execucao' => '11144477735'];
    }

    function testColunasSaemNaOrdemDoModeloDePlanilha()
    {
        $esperada = [
            'identificador_edital',
            'cpf_responsavel_execucao',
            'cnpj_responsavel_execucao',
            'valor_recebido_pnab',
            'data_recebimento_recurso',
            'data_inicio_execucao',
            'data_termino_execucao',
            'recebeu_recursos_outras_fontes',
            'valor_recursos_outras_fontes',
            'qtd_pessoas_remuneradas_exec',
            'cpfs_remunerados',
            'formato_execucao',
            'cep_local_execucao',
        ];

        $this->assertSame($esperada, array_column($this->service()->colunas(), 'chave'));
    }

    function testColunasDeclaramTipoObrigatoriedadeEEdicao()
    {
        $porChave = array_column($this->service()->colunas(), null, 'chave');

        $this->assertTrue($porChave['data_recebimento_recurso']['obrigatoria']);
        $this->assertFalse($porChave['cep_local_execucao']['obrigatoria']);
        $this->assertFalse($porChave['cpf_responsavel_execucao']['editavel'], 'CPF vem do agente e não se digita na grade');
        $this->assertTrue($porChave['valor_recebido_pnab']['editavel'], 'a categoria sugere o valor, o gestor corrige');
        $this->assertSame('moeda', $porChave['valor_recebido_pnab']['tipo']);
    }

    function testTodaColunaTemRotuloProprio()
    {
        foreach ($this->service()->colunas() as $coluna) {
            $this->assertNotSame($coluna['chave'], $coluna['label'], "a coluna {$coluna['chave']} caiu no rótulo cru");
        }
    }

    function testAsColunasDeFichaSaoExatamenteAsMapeaveisNoDePara()
    {
        $daFicha = array_keys(array_filter(
            LinhasBeneficiariosService::COLUNAS,
            fn(array $def) => $def['fonte'] === LinhasBeneficiariosService::FONTE_FICHA,
        ));

        $this->assertEqualsCanonicalizing(
            array_keys(ResultadoDeParaService::COLUNAS),
            $daFicha,
            'coluna mapeável sem fonte de ficha aqui teria o valor do de/para descartado no merge',
        );
    }

    function testOGestorVenceAFichaQueVenceOCalculado()
    {
        $linha = $this->service()->mesclar(
            ['valor_recebido_pnab' => '15000.00', 'cep_local_execucao' => '00000-000'],
            ['cep_local_execucao' => '57072-900', 'qtd_pessoas_remuneradas_exec' => '2'],
            ['cep_local_execucao' => '70000-000'],
        );

        $this->assertSame('70000-000', $linha['cep_local_execucao']['valor']);
        $this->assertSame(LinhasBeneficiariosService::FONTE_GESTOR, $linha['cep_local_execucao']['fonte']);
        $this->assertSame('2', $linha['qtd_pessoas_remuneradas_exec']['valor']);
        $this->assertSame(LinhasBeneficiariosService::FONTE_FICHA, $linha['qtd_pessoas_remuneradas_exec']['fonte']);
        $this->assertSame('15000.00', $linha['valor_recebido_pnab']['valor']);
        $this->assertSame(LinhasBeneficiariosService::FONTE_AUTOMATICA, $linha['valor_recebido_pnab']['fonte']);
    }

    function testCelulaApagadaPeloGestorNaoVoltaAserPreenchidaPelaFicha()
    {
        $linha = $this->service()->mesclar(
            ['cep_local_execucao' => '00000-000'],
            ['cep_local_execucao' => '57072-900'],
            ['cep_local_execucao' => ''],
        );

        $this->assertNull($linha['cep_local_execucao']['valor'], 'apagar é decisão do gestor, e a ficha não a desfaz');
        $this->assertSame(LinhasBeneficiariosService::FONTE_GESTOR, $linha['cep_local_execucao']['fonte']);
    }

    function testColunaIntocadaPeloGestorSegueVindoDaFicha()
    {
        $linha = $this->service()->mesclar(
            ['cep_local_execucao' => '00000-000'],
            ['cep_local_execucao' => '57072-900'],
            [],
        );

        $this->assertSame('57072-900', $linha['cep_local_execucao']['valor']);
        $this->assertSame(LinhasBeneficiariosService::FONTE_FICHA, $linha['cep_local_execucao']['fonte']);
    }

    function testColunaSemNenhumaFonteFicaNulaComAFonteEsperada()
    {
        $linha = $this->service()->mesclar([], [], []);

        $this->assertNull($linha['data_recebimento_recurso']['valor']);
        $this->assertSame(LinhasBeneficiariosService::FONTE_GESTOR, $linha['data_recebimento_recurso']['fonte']);
        $this->assertNull($linha['formato_execucao']['valor']);
        $this->assertSame(LinhasBeneficiariosService::FONTE_FICHA, $linha['formato_execucao']['fonte']);
    }

    function testListaVaziaNaoContaComoPreenchida()
    {
        $daFicha = $this->service()->mesclar([], ['recebeu_recursos_outras_fontes' => ['Sim - Setor Privado']], []);
        $this->assertSame(['Sim - Setor Privado'], $daFicha['recebeu_recursos_outras_fontes']['valor']);

        $esvaziada = $this->service()->mesclar([], ['recebeu_recursos_outras_fontes' => ['Sim - Setor Privado']], ['recebeu_recursos_outras_fontes' => []]);
        $this->assertNull($esvaziada['recebeu_recursos_outras_fontes']['valor'], 'lista esvaziada pelo gestor fica vazia');
    }

    function testBooleanoFalsoNaFichaNaoDeclaraOutrasFontes()
    {
        $service = $this->service();
        $linha = $service->mesclar($this->linhaMinima(), ['recebeu_recursos_outras_fontes' => false], ['data_recebimento_recurso' => '10/01/2026']);

        $this->assertNull($linha['recebeu_recursos_outras_fontes']['valor'], 'não recebeu equivale a não declarar');
        $this->assertSame([], $service->pendencias($linha), 'sem declaração, o valor de outras fontes não é cobrado');
    }

    function testPendenciasApontamAsObrigatoriasQueFaltam()
    {
        $service = $this->service();
        $linha = $service->mesclar(['identificador_edital' => 'Edital X', 'valor_recebido_pnab' => '15000.00'], [], []);

        $this->assertSame(['data_recebimento_recurso', LinhasBeneficiariosService::PENDENCIA_RESPONSAVEL], $service->pendencias($linha));
    }

    function testLinhaCompletaNaoTemPendencia()
    {
        $service = $this->service();
        $linha = $service->mesclar($this->linhaMinima(), [], ['data_recebimento_recurso' => '10/01/2026']);

        $this->assertSame([], $service->pendencias($linha));
    }

    function testLinhaSemDocumentoDoResponsavelPende()
    {
        $service = $this->service();
        $semDocumento = $service->mesclar(['identificador_edital' => 'Edital X', 'valor_recebido_pnab' => '15000.00'], [], ['data_recebimento_recurso' => '10/01/2026']);

        $this->assertSame([LinhasBeneficiariosService::PENDENCIA_RESPONSAVEL], $service->pendencias($semDocumento), 'sem CPF nem CNPJ o validador recusa a planilha inteira');

        $comCnpj = $service->mesclar(
            ['identificador_edital' => 'Edital X', 'valor_recebido_pnab' => '15000.00', 'cnpj_responsavel_execucao' => '11222333000181'],
            [],
            ['data_recebimento_recurso' => '10/01/2026'],
        );
        $this->assertSame([], $service->pendencias($comCnpj), 'só o CNPJ já satisfaz a exigência');
    }

    function testNegarOutrasFontesDispensaOValorERecusaValorInformado()
    {
        $service = $this->service();
        $base = $this->linhaMinima();
        $comum = ['data_recebimento_recurso' => '10/01/2026'];

        $negouSemValor = $service->mesclar($base, [], $comum + ['recebeu_recursos_outras_fontes' => ['Não, a ação foi integralmente realizada com recursos do PNAB']]);
        $this->assertSame([], $service->pendencias($negouSemValor), 'quem nega não tem valor a informar');

        $negouComValor = $service->mesclar($base, [], $comum + ['recebeu_recursos_outras_fontes' => ['Não, a ação foi integralmente realizada com recursos do PNAB'], 'valor_recursos_outras_fontes' => '1000.00']);
        $this->assertSame(['valor_recursos_outras_fontes'], $service->pendencias($negouComValor), 'negar e informar valor é contradição na mesma linha');

        $declarouFonte = $service->mesclar($base, [], $comum + ['recebeu_recursos_outras_fontes' => ['Sim - Setor Privado']]);
        $this->assertSame(['valor_recursos_outras_fontes'], $service->pendencias($declarouFonte), 'declarou a fonte, tem que dizer quanto');
    }

    function testAsDatasDaLinhaSeguemAOrdemDosFatos()
    {
        $service = $this->service();
        $base = $this->linhaMinima();

        $terminoAntesDoInicio = $service->mesclar($base, [], ['data_recebimento_recurso' => '10/01/2026', 'data_inicio_execucao' => '01/02/2026', 'data_termino_execucao' => '01/01/2026']);
        $this->assertSame(['data_termino_execucao'], $service->pendencias($terminoAntesDoInicio));

        $execucaoAntesDoRecurso = $service->mesclar($base, [], ['data_recebimento_recurso' => '10/03/2026', 'data_inicio_execucao' => '01/02/2026']);
        $this->assertSame(['data_inicio_execucao'], $service->pendencias($execucaoAntesDoRecurso), 'não se executa antes de receber o recurso');

        $naOrdem = $service->mesclar($base, [], ['data_recebimento_recurso' => '10/01/2026', 'data_inicio_execucao' => '01/02/2026', 'data_termino_execucao' => '01/03/2026']);
        $this->assertSame([], $service->pendencias($naOrdem));

        $mesmoDia = $service->mesclar($base, [], ['data_recebimento_recurso' => '10/01/2026', 'data_inicio_execucao' => '10/01/2026', 'data_termino_execucao' => '10/01/2026']);
        $this->assertSame([], $service->pendencias($mesmoDia), 'receber e executar no mesmo dia é válido');

        $soInicio = $service->mesclar($base, [], ['data_recebimento_recurso' => '10/01/2026', 'data_inicio_execucao' => '01/02/2026']);
        $this->assertSame([], $service->pendencias($soInicio), 'sem término não há o que comparar');
    }

    function testOEnvioNaoCobraADataQueDeixouDeSerObrigatoria()
    {
        $service = $this->service();
        $semData = $service->mesclar($this->linhaMinima(), [], []);

        $this->assertSame([], $service->faltasParaEnvio($semData), 'a data de recebimento não segura a planilha');
        $this->assertSame(['data_recebimento_recurso'], $service->pendencias($semData), 'mas a grade segue cobrando do gestor');
    }

    function testEnvioSeguraALinhaQueSeContradiz()
    {
        $service = $this->service();

        $qtdSemOsCpfs = $service->mesclar($this->linhaMinima(), ['qtd_pessoas_remuneradas_exec' => '2'], []);
        $this->assertSame([], $service->faltasParaEnvio($qtdSemOsCpfs));
        $this->assertSame(['data_recebimento_recurso'], $service->pendencias($qtdSemOsCpfs), 'quantidade sem CPF deixou de ser cobrada: era regra nossa, não do validador');

        $outraFonteSemValor = $service->mesclar($this->linhaMinima(), ['recebeu_recursos_outras_fontes' => ['Sim - Setor Privado']], []);
        $this->assertSame(['valor_recursos_outras_fontes'], $service->faltasParaEnvio($outraFonteSemValor), 'o validador recusa por valor_outras_fontes_obrigatorio');

        $terminoAntesDoInicio = $service->mesclar($this->linhaMinima(), [], ['data_inicio_execucao' => '10/03/2026', 'data_termino_execucao' => '01/03/2026']);
        $this->assertSame(['data_termino_execucao'], $service->faltasParaEnvio($terminoAntesDoInicio));
    }

    function testEnvioExigeIdentificadorValorEResponsavel()
    {
        $service = $this->service();

        $semValor = $service->mesclar(['identificador_edital' => 'Edital X', 'cpf_responsavel_execucao' => '11144477735'], [], []);
        $this->assertSame(['valor_recebido_pnab'], $service->faltasParaEnvio($semValor));

        $semResponsavel = $service->mesclar(['identificador_edital' => 'Edital X', 'valor_recebido_pnab' => '15000.00'], [], []);
        $this->assertSame([LinhasBeneficiariosService::PENDENCIA_RESPONSAVEL], $service->faltasParaEnvio($semResponsavel));

        $soCnpj = $service->mesclar(['identificador_edital' => 'Edital X', 'valor_recebido_pnab' => '15000.00', 'cnpj_responsavel_execucao' => '11222333000181'], [], []);
        $this->assertSame([], $service->faltasParaEnvio($soCnpj), 'CNPJ basta no lugar do CPF');
    }

    function testLinhaDeEnvioSaiComValorCruESemAsColunasVazias()
    {
        $linha = $this->service()->mesclar(
            ['identificador_edital' => 'Edital X', 'cpf_responsavel_execucao' => '11144477735', 'valor_recebido_pnab' => '15000.00'],
            [],
            ['data_recebimento_recurso' => '10/01/2026', 'cep_local_execucao' => ''],
        );

        $enviavel = $this->service()->paraOEnvio($linha);

        $this->assertSame('15000.00', $enviavel['valor_recebido_pnab'], 'o contrato recebe o valor, não a fonte dele');
        $this->assertSame('10/01/2026', $enviavel['data_recebimento_recurso']);
        $this->assertArrayNotHasKey('cep_local_execucao', $enviavel, 'campo opcional vazio é omitido, nunca enviado em branco');
        $this->assertArrayNotHasKey('data_inicio_execucao', $enviavel);
    }

    function testColunaDeOutrasFontesOfereceOVocabularioDoContrato()
    {
        $porChave = array_column($this->service()->colunas(), null, 'chave');

        $opcoes = $porChave['recebeu_recursos_outras_fontes']['opcoes'];
        $this->assertContains('Não, a ação foi integralmente realizada com recursos do PNAB', $opcoes, 'o validador recusou a forma curta \'Não\' por erro de domínio');
        $this->assertContains('Sim - Governo Estadual', $opcoes, 'vocabulário informado pela equipe do CultBr');
        $this->assertSame('Presencial Fixo', $porChave['formato_execucao']['opcoes'][0], 'a equipe do CultBr informou as formas curtas, não as do padrão de dados');
        $this->assertCount(5, $porChave['formato_execucao']['opcoes']);
        $this->assertSame('selecao', $porChave['formato_execucao']['tipo'], 'o contrato não aceita lista nesta coluna');
    }

    function testOutrasFontesExigemOValor()
    {
        $service = $this->service();
        $linha = $service->mesclar(
            $this->linhaMinima(),
            ['recebeu_recursos_outras_fontes' => ['Sim - Governo Estadual']],
            ['data_recebimento_recurso' => '10/01/2026'],
        );

        $this->assertSame(['valor_recursos_outras_fontes'], $service->pendencias($linha));
    }

    function testCnpjSozinhoSatisfazOResponsavel()
    {
        $service = $this->service();

        $pj = $service->mesclar(['cnpj_responsavel_execucao' => '11222333000181'], [], []);
        $this->assertTrue($service->temResponsavel($pj));

        $pf = $service->mesclar(['cpf_responsavel_execucao' => '11144477735'], [], []);
        $this->assertTrue($service->temResponsavel($pf));

        $this->assertFalse($service->temResponsavel($service->mesclar([], [], [])));
    }
}
