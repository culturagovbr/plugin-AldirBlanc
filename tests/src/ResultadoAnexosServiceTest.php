<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Services\FormularioResultadoService;
use AldirBlanc\Services\ResultadoAnexosService;
use MapasCulturais\ApiQuery;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\OpportunityFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\FakeIntegrationProvider;
use Tests\AldirBlanc\Traits\BuildsAnexos;
use Tests\AldirBlanc\Traits\BuildsPublishedFinalPhase;
use Tests\AldirBlanc\Traits\SendsOpportunityThroughJob;
use Tests\Traits\UserDirector;

/** Guarda e conferência dos documentos anexados ao resultado. */
class ResultadoAnexosServiceTest extends TestCase
{
    use BuildsAnexos;
    use BuildsPublishedFinalPhase;
    use SendsOpportunityThroughJob;
    use UserDirector;

    private const REGRA = ['tipo' => 'visibilidade', 'efeito' => ['mostrar' => true], 'quando' => ['operador' => 'eq', 'valor' => true, 'fonteIdQuestaoEtapa' => 900]];

    private function estrutura(array $documentos = [], int $versao = 296): array
    {
        return ['id_formulario' => 109, 'id_formulario_versao' => $versao, 'versao' => 5, 'secoes' => [['etapas' => [['questoes' => [
            ['id_questao' => 10, 'id_questao_etapa' => 900, 'nome' => 'Houve?', 'tipo_questao' => 'booleano', 'tag' => 'houve', 'requerida' => false, 'config' => []],
            ...($documentos ?: [
                $this->questaoDeDocumento(30, ['formatos' => ['.pdf'], 'multiplos' => false]),
                $this->questaoDeDocumento(31, ['multiplos' => true]),
                $this->questaoDeDocumento(32, [], ['tipo_questao' => 'múltiplos anexos']),
            ]),
        ]]]]]];
    }

    private function noEdital(callable $exercicio, ?array $estrutura = null): void
    {
        $estrutura ??= $this->estrutura();

        $this->comFormularioResultado(function () use ($exercicio, $estrutura) {
            [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser(), 'Subsite Anexos ' . uniqid());

            $exercicio(new ResultadoAnexosService($raiz, $estrutura), $raiz, $estrutura);
        }, $estrutura);
    }

    /** Anexa e devolve o arquivo guardado. */
    private function anexa(ResultadoAnexosService $anexos, int $idQuestao, string $nome = 'ata.pdf'): OpportunityFile
    {
        $arquivo = $this->arquivo($nome);

        $this->assertNull($anexos->anexar($idQuestao, $arquivo));

        return $this->apagaNoFim($arquivo);
    }

    private function doEdital(Opportunity $raiz): array
    {
        return $this->app->repo('OpportunityFile')->findBy(['owner' => $raiz], ['id' => 'ASC']);
    }

    private function respostas(Opportunity $raiz, array $estrutura): array
    {
        return (new FormularioResultadoService())->savedRespostas((int) $raiz->id, $estrutura);
    }

    private function gravaRespostas(Opportunity $raiz, int $versao, array $respostas): void
    {
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_FORM, json_encode([
            'id_formulario' => 109, 'id_formulario_versao' => $versao, 'versao' => 5, 'respostas' => $respostas,
        ]));
    }

    public function testDocumentoFicaPrivadoNoEditalELigadoAQuestao(): void
    {
        $this->noEdital(function (ResultadoAnexosService $anexos, Opportunity $raiz, array $estrutura) {
            $arquivo = $this->anexa($anexos, 30);

            $this->assertTrue($arquivo->private);
            $this->assertSame(ResultadoAnexosService::GRUPO, $arquivo->group);
            $this->assertSame($raiz->id, $arquivo->owner->id);
            $this->assertStringStartsWith(PRIVATE_FILES_PATH, $arquivo->getPath());
            $this->assertSame(self::PDF, file_get_contents($arquivo->getPath()));
            $this->assertSame([30 => [$arquivo->id]], $this->respostas($raiz, $estrutura));
            $this->assertSame([30 => [['id' => $arquivo->id, 'nome' => 'ata.pdf', 'url' => $arquivo->url]]], $anexos->paraATela());
        });
    }

    public function testBaixaQuemControlaOEditalEOAdmin(): void
    {
        $this->noEdital(function (ResultadoAnexosService $anexos, Opportunity $raiz) {
            $arquivo = $this->anexa($anexos, 30);

            $this->assertTrue($arquivo->canUser('view', $raiz->owner->user));
            $this->assertTrue($arquivo->canUser('view', $this->userDirector->createUser('admin')));
            $this->assertFalse($arquivo->canUser('view', $this->userDirector->createUser()));
        });
    }

    #[DataProvider('recusados')]
    public function testArquivoForaDoQueAQuestaoAceitaERecusado(array $config, string $nome, ?int $tamanho, ?string $motivo, string $conteudo = self::PDF): void
    {
        $estrutura = $this->estrutura([$this->questaoDeDocumento(30, $config)]);

        $this->noEdital(function (ResultadoAnexosService $anexos, Opportunity $raiz, array $estrutura) use ($nome, $tamanho, $motivo, $conteudo) {
            $arquivo = $this->arquivo($nome, $conteudo, $tamanho);

            $this->assertSame($motivo, $anexos->anexar(30, $arquivo));

            if ($motivo === null) {
                $this->apagaNoFim($arquivo);
                return;
            }

            $this->assertSame([[], []], [$this->doEdital($raiz), $this->respostas($raiz, $estrutura)]);
        }, $estrutura);
    }

    public static function recusados(): array
    {
        $mb = 1024 * 1024;

        return [
            'extensão fora da lista' => [['formatos' => ['.pdf']], 'ata.docx', null, 'Formatos aceitos: .pdf.'],
            'sem extensão' => [['formatos' => ['.pdf', '.csv']], 'ata', null, 'Formatos aceitos: .pdf, .csv.'],
            'extensão em maiúsculas' => [['formatos' => ['.pdf']], 'ATA.PDF', null, null],
            'lista em maiúsculas' => [['formatos' => ['.PDF']], 'ata.pdf', null, null],
            'sem formatos declarados' => [[], 'ata.docx', null, null],
            'extensão que o sistema bloqueia' => [[], 'ata.sql', null, 'Extensão de arquivo não permitida.'],
            'conteúdo que o sistema bloqueia' => [[], 'ata.txt', null, 'Tipo de arquivo não permitido', '<!doctype html><html><body><script>1</script></body></html>'],
            'conteúdo fora dos tipos do grupo' => [[], 'ata.pdf', null, 'Tipo de arquivo não permitido.', ''],
            'no teto padrão' => [[], 'ata.pdf', 10 * $mb, null],
            'acima do teto padrão' => [[], 'ata.pdf', 10 * $mb + 1, 'O arquivo passa de 10 MB.'],
            'no tamanho declarado' => [['tamanhoMaxMb' => 2], 'ata.pdf', 2 * $mb, null],
            'acima do tamanho declarado' => [['tamanhoMaxMb' => 2], 'ata.pdf', 2 * $mb + 1, 'O arquivo passa de 2 MB.'],
            'declarado acima do contrato' => [['tamanhoMaxMb' => 50], 'ata.pdf', 10 * $mb + 1, 'O arquivo passa de 10 MB.'],
        ];
    }

    #[DataProvider('quantidades')]
    public function testQuantidadeEAQueAQuestaoDeclara(int $idQuestao, ?string $motivo): void
    {
        $this->noEdital(function (ResultadoAnexosService $anexos, Opportunity $raiz) use ($idQuestao, $motivo) {
            $primeiro = $this->anexa($anexos, $idQuestao);
            $segundo = $this->arquivo('parecer.pdf');

            $this->assertSame($motivo, $anexos->anexar($idQuestao, $segundo));

            if ($motivo === null) {
                $this->assertSame([$primeiro->id, $this->apagaNoFim($segundo)->id], array_keys($anexos->porQuestao()[$idQuestao]));
            }
        });
    }

    public static function quantidades(): array
    {
        return [
            'um só' => [30, 'Esta questão aceita um arquivo só. Retire o atual para anexar outro.'],
            'anexo que declara múltiplos' => [31, null],
            'múltiplos anexos' => [32, null],
        ];
    }

    public function testCadaQuestaoContaOsProprios(): void
    {
        $this->noEdital(function (ResultadoAnexosService $anexos) {
            $daMultipla = $this->anexa($anexos, 31);
            $daUnica = $this->anexa($anexos, 30, 'parecer.pdf');

            $this->assertSame([[$daUnica->id], [$daMultipla->id]], [array_keys($anexos->porQuestao()[30]), array_keys($anexos->porQuestao()[31])]);
        });
    }

    #[DataProvider('questoesFechadas')]
    public function testQuestaoQueNaoRecebeArquivoRecusa(array $questao): void
    {
        $this->noEdital(function (ResultadoAnexosService $anexos, Opportunity $raiz) {
            $this->assertSame('Esta questão não recebe arquivo.', $anexos->anexar(40, $this->arquivo('ata.pdf')));
            $this->assertSame([], $this->doEdital($raiz));
        }, $this->estrutura([$questao + ['id_questao_etapa' => 901]]));
    }

    public static function questoesFechadas(): array
    {
        $documento = ['id_questao' => 40, 'nome' => 'Documento', 'tipo_questao' => 'anexo', 'tag' => 'documento', 'requerida' => false, 'config' => []];

        return [
            'planilha' => [['nome' => 'Planilha de resultados', 'tag' => 'planilha_resultados'] + $documento],
            'somente leitura' => [['somente_leitura' => true] + $documento],
            'invisível' => [['config' => ['regras' => ['itens' => [self::REGRA]]]] + $documento],
            'de outro tipo' => [['tipo_questao' => 'texto'] + $documento],
            'de outro formulário' => [['id_questao' => 41] + $documento],
        ];
    }

    public function testQuestaoQueARegraMostraRecebeArquivo(): void
    {
        $estrutura = $this->estrutura([$this->questaoDeDocumento(40, ['regras' => ['itens' => [self::REGRA]]])]);

        $this->noEdital(function (ResultadoAnexosService $anexos, Opportunity $raiz) {
            $this->gravaRespostas($raiz, 296, [10 => true]);
            $arquivo = $this->anexa($anexos, 40);

            $this->assertSame([40], array_keys($anexos->porQuestao()));

            (new FormularioResultadoService())->gravaRespostas($raiz, [10 => false], $this->estrutura([$this->questaoDeDocumento(40)]));
            $this->assertSame([$arquivo->id], array_keys($anexos->porQuestao()[40]), 'a questão que deixa de aparecer guarda o documento');
        }, $estrutura);
    }

    #[DataProvider('dependencias')]
    public function testEditalQueDependeDoCultBrNaoRecebeArquivo(bool $possui, bool $requerida, ?string $motivo): void
    {
        $estrutura = $this->estrutura([$this->questaoDeDocumento(30), $this->questaoDeDocumento(31, [], ['requerida' => $requerida])]);

        $this->noEdital(function (ResultadoAnexosService $anexos) use ($possui, $motivo) {
            FakeIntegrationProvider::$aoBuscarModelo = fn() => ['possui_planilha' => $possui];
            $arquivo = $this->arquivo('ata.pdf');

            $this->assertSame($motivo, $anexos->anexar(30, $arquivo));

            if ($motivo === null) {
                $this->apagaNoFim($arquivo);
            }
        }, $estrutura);
    }

    public static function dependencias(): array
    {
        return [
            'planilha e documento requerido' => [true, true, 'Esta ação exige planilha e documentos, e o CultBR ainda não recebe os dois juntos.'],
            'planilha e documento opcional' => [true, false, null],
            'documento requerido sem planilha' => [false, true, null],
        ];
    }

    public function testDocumentoRetiradoEApagado(): void
    {
        $this->noEdital(function (ResultadoAnexosService $anexos, Opportunity $raiz, array $estrutura) {
            [$fica, $sai] = [$this->anexa($anexos, 31), $this->anexa($anexos, 31, 'parecer.pdf')];
            $caminho = $sai->getPath();

            $this->assertTrue($anexos->retirar(31, $sai->id));

            $this->assertFileDoesNotExist($caminho);
            $this->assertSame([$fica], $this->doEdital($raiz));
            $this->assertSame([31 => [$fica->id]], $this->respostas($raiz, $estrutura));

            $this->assertTrue($anexos->retirar(31, $fica->id));
            $this->assertSame([[], []], [$this->respostas($raiz, $estrutura), $anexos->paraATela()]);
        });
    }

    public function testSoSeRetiraOQueEDaQuestao(): void
    {
        $this->noEdital(function (ResultadoAnexosService $anexos, Opportunity $raiz) {
            $arquivo = $this->anexa($anexos, 30);

            $this->assertFalse($anexos->retirar(31, $arquivo->id));
            $this->assertFalse($anexos->retirar(30, $arquivo->id + 1));
            $this->assertSame([$arquivo], $this->doEdital($raiz));
        });
    }

    public function testSoValeArquivoDoProprioEditalEDoGrupo(): void
    {
        $this->noEdital(function (ResultadoAnexosService $anexos, Opportunity $raiz) {
            $doOutro = $this->anexa($anexos, 30);
            $copia = $this->raizElegivel($raiz->owner->user, $raiz->subsite);
            $daCopia = new ResultadoAnexosService($copia, $this->estrutura());
            $regulamento = $this->arquivo('regulamento.pdf');
            $regulamento->owner = $copia;
            $regulamento->group = 'rules';
            $regulamento->save(true);
            $this->apagaNoFim($regulamento);
            $this->gravaRespostas($copia, 296, [30 => [$doOutro->id], 31 => [$regulamento->id], 32 => [999999]]);

            $this->assertSame([], $daCopia->porQuestao());
            $this->assertFalse($daCopia->retirar(30, $doOutro->id));
            $this->assertSame([30], array_keys($anexos->porQuestao()));

            $proprio = $this->anexa($daCopia, 30);
            $this->assertSame([30 => [$proprio->id]], array_map(array_keys(...), $daCopia->porQuestao()));
        });
    }

    #[DataProvider('trocasDeVersao')]
    public function testNaTrocaDeVersaoSaemOsDocumentosDaAnterior(callable $grava, array $questoes): void
    {
        $this->noEdital(function (ResultadoAnexosService $anexos, Opportunity $raiz) use ($grava, $questoes) {
            $antigo = $this->anexa($anexos, 30);
            $caminho = $antigo->getPath();
            $nova = $this->estrutura(versao: 297);
            $daNova = new ResultadoAnexosService($raiz, $nova);

            $this->assertSame([], $daNova->paraATela());
            $this->assertFileExists($caminho, 'só a gravação na versão nova retira o documento');

            $grava($this, $raiz, $nova, $daNova);

            $this->assertFileDoesNotExist($caminho);
            $this->assertSame($questoes, array_keys($daNova->porQuestao()));
            $this->assertCount(count($questoes), $this->doEdital($raiz));
        });
    }

    public static function trocasDeVersao(): array
    {
        return [
            'ao responder' => [fn(self $t, Opportunity $raiz, array $nova) => (new FormularioResultadoService())->saveRespostas($raiz, [10 => true], $nova), []],
            'ao anexar' => [fn(self $t, Opportunity $raiz, array $nova, ResultadoAnexosService $daNova) => $t->anexa($daNova, 31, 'parecer.pdf'), [31]],
        ];
    }

    public function testNaMesmaVersaoNadaERetirado(): void
    {
        $this->noEdital(function (ResultadoAnexosService $anexos, Opportunity $raiz, array $estrutura) {
            $arquivo = $this->anexa($anexos, 30);
            $solto = $this->arquivo('solto.pdf');
            $solto->owner = $raiz;
            $solto->group = ResultadoAnexosService::GRUPO;
            $solto->save(true);
            $this->apagaNoFim($solto);

            (new FormularioResultadoService())->saveRespostas($raiz, [10 => true], $estrutura);

            $this->assertSame([$arquivo, $solto], $this->doEdital($raiz));
            $this->assertSame([30 => [$arquivo->id], 10 => true], $this->respostas($raiz, $estrutura));
        });
    }

    #[DataProvider('consultasPublicas')]
    public function testConsultaPublicaDoEditalNaoTrazODocumento(array $consulta, bool $isPelaFase = false): void
    {
        $this->noEdital(function (ResultadoAnexosService $anexos, Opportunity $raiz) use ($consulta, $isPelaFase) {
            $this->anexa($anexos, 30, 'nome-reservado.pdf');
            $regulamento = $this->arquivo('regulamento.pdf');
            $regulamento->owner = $raiz;
            $regulamento->group = 'rules';
            $regulamento->save(true);
            $this->apagaNoFim($regulamento);
            $id = $isPelaFase ? $raiz->lastPhase->id : $raiz->id;
            $this->logout();

            $retorno = json_encode((new ApiQuery(Opportunity::class, ['id' => "EQ({$id})"] + $consulta))->findOne());

            $this->assertStringContainsString('regulamento', $retorno);
            $this->assertStringNotContainsString('nome-reservado', $retorno);
        });
    }

    public static function consultasPublicas(): array
    {
        $grupo = ResultadoAnexosService::GRUPO;

        return [
            'tudo' => [['@select' => '*']],
            'arquivos' => [['@select' => 'id,files']],
            'grupo' => [['@select' => "id,files.rules,files.{$grupo}"]],
            'pela fase' => [['@select' => 'id,parent.{id,files}', 'status' => 'EQ(-1)'], true],
            'formato antigo' => [['@select' => 'id', '@files' => "(rules,{$grupo}):name,url"]],
        ];
    }
}
