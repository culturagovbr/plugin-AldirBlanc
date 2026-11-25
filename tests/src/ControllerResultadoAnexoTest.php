<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Services\FormularioResultadoService;
use Laminas\Diactoros\Response;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Exceptions\Halt;
use MapasCulturais\Exceptions\SaveFileError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\FakeIntegrationProvider;
use Tests\AldirBlanc\Doubles\TestableController;
use Tests\AldirBlanc\Traits\BuildsAnexos;
use Tests\AldirBlanc\Traits\BuildsPublishedFinalPhase;
use Tests\AldirBlanc\Traits\SendsOpportunityThroughJob;
use Tests\Traits\UserDirector;

/** POST /aldirblanc/resultadoAnexo e /aldirblanc/resultadoAnexoRetirar — os documentos do formulário de resultado. */
class ControllerResultadoAnexoTest extends TestCase
{
    use BuildsAnexos {
        tearDown as private apagaAnexos;
    }
    use BuildsPublishedFinalPhase;
    use SendsOpportunityThroughJob;
    use UserDirector;

    private const VERSAO = 296;

    private array $globais = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->globais = [$_FILES, $_SERVER];
    }

    protected function tearDown(): void
    {
        [$_FILES, $_SERVER] = $this->globais;
        $this->apagaAnexos();
    }

    private function noEdital(callable $exercicio, bool $publicado = true, array $documentos = []): void
    {
        $estrutura = ['id_formulario' => 109, 'id_formulario_versao' => self::VERSAO, 'versao' => 5, 'secoes' => [['etapas' => [['questoes' => [
            ['id_questao' => 10, 'id_questao_etapa' => 900, 'nome' => 'Comentários', 'tipo_questao' => 'texto', 'tag' => 'comentarios', 'requerida' => true, 'config' => []],
            ...($documentos ?: [$this->questaoDeDocumento(30, ['formatos' => ['.pdf']]), $this->questaoDeDocumento(31, ['multiplos' => true])]),
        ]]]]]];

        $this->comFormularioResultado(function () use ($exercicio, $publicado) {
            $owner = $this->userDirector->createUser();

            if ($publicado) {
                [$raiz] = $this->editalComFaseFinalPublicada($owner, 'Subsite Anexo ' . uniqid());
            } else {
                $raiz = $this->raizElegivel($owner, $this->integrationSubsite($owner, 'Subsite Anexo ' . uniqid()));
                $this->faseFinal($raiz, publicada: false);
            }

            $this->login($owner);
            $exercicio($raiz);
        }, $estrutura);
    }

    private function chama(string $metodo, array $dados): array
    {
        $controller = new TestableController();
        $controller->data = $dados;
        $this->app->response = new Response();

        try {
            $controller->$metodo();
            $this->fail('Esperava que o controller encerrasse a resposta com Halt');
        } catch (Halt) {
        }

        return json_decode((string) $this->app->response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function anexa(Opportunity $raiz, int $idQuestao, ?array $arquivo, array $extras = []): array
    {
        $_FILES = $arquivo === null ? [] : ['arquivo' => $arquivo];
        $resposta = $this->chama('callPostResultadoAnexo', $extras + ['opportunityId' => $raiz->id, 'id_questao' => (string) $idQuestao]);

        foreach ($this->app->repo('OpportunityFile')->findBy(['owner' => $raiz]) as $guardado) {
            $this->apagaNoFim($guardado);
        }

        return $resposta;
    }

    private function retira(Opportunity $raiz, int $idQuestao, int $idArquivo, array $extras = []): array
    {
        return $this->chama('callPostResultadoAnexoRetirar', $extras + ['opportunityId' => $raiz->id, 'id_questao' => $idQuestao, 'id_arquivo' => $idArquivo]);
    }

    private function salva(Opportunity $raiz, array $respostas): array
    {
        return $this->chama('callPostResultadoFormulario', ['opportunityId' => $raiz->id, 'respostas' => $respostas]);
    }

    private function codigo(): int
    {
        return $this->app->response->getStatusCode();
    }

    private function totalDeArquivos(Opportunity $raiz): int
    {
        return $this->app->repo('OpportunityFile')->count(['owner' => $raiz]);
    }

    public function testAnexarDevolveOEstadoComOArquivoDaQuestao(): void
    {
        $this->noEdital(function (Opportunity $raiz) {
            $payload = $this->anexa($raiz, 31, $this->upload('Ata da Comissão.PDF'));

            $this->assertSame(200, $this->codigo());
            $this->assertSame(['ata-da-comissao.pdf'], array_column($payload['anexos'][31], 'nome'));
            $this->assertStringContainsString((string) $payload['anexos'][31][0]['id'], $payload['anexos'][31][0]['url']);
            $this->assertSame([], $payload['respostas'], 'o arquivo não vai como resposta para a tela');
            $this->assertSame($payload['anexos'], $this->chama('callGetResultadoFormulario', ['opportunityId' => $raiz->id])['anexos']);
        });
    }

    public function testSemArquivoOEstadoTrazOsAnexosVazios(): void
    {
        $this->noEdital(function (Opportunity $raiz) {
            $this->chama('callGetResultadoFormulario', ['opportunityId' => $raiz->id]);

            $this->assertStringContainsString('"anexos":{}', (string) $this->app->response->getBody());
        });
    }

    public function testRetirarDevolveOEstadoSemOArquivo(): void
    {
        $this->noEdital(function (Opportunity $raiz) {
            $id = $this->anexa($raiz, 30, $this->upload('ata.pdf'))['anexos'][30][0]['id'];

            $payload = $this->retira($raiz, 30, $id);

            $this->assertSame(200, $this->codigo());
            $this->assertSame([], $payload['anexos']);
            $this->assertSame(0, $this->totalDeArquivos($raiz));
        });
    }

    public function testArquivoQueNaoEDaQuestaoNaoERetirado(): void
    {
        $this->noEdital(function (Opportunity $raiz) {
            $id = $this->anexa($raiz, 30, $this->upload('ata.pdf'))['anexos'][30][0]['id'];

            $payload = $this->retira($raiz, 31, $id);

            $this->assertSame([400, 'Arquivo não encontrado nesta questão.'], [$this->codigo(), $payload['data']]);
            $this->assertSame(1, $this->totalDeArquivos($raiz));
        });
    }

    public function testRecusaVoltaComOMotivo(): void
    {
        $this->noEdital(function (Opportunity $raiz) {
            $payload = $this->anexa($raiz, 30, $this->upload('ata.docx'));

            $this->assertSame([400, 'Formatos aceitos: .pdf.'], [$this->codigo(), $payload['data']]);
            $this->assertSame(0, $this->totalDeArquivos($raiz));
        });
    }

    public function testQuemNaoControlaOEditalNaoAnexaNemRetira(): void
    {
        $this->noEdital(function (Opportunity $raiz) {
            $id = $this->anexa($raiz, 30, $this->upload('ata.pdf'))['anexos'][30][0]['id'];
            $this->login($this->userDirector->createUser());

            $this->anexa($raiz, 31, $this->upload('parecer.pdf'));
            $this->assertSame(403, $this->codigo());

            $this->retira($raiz, 30, $id);
            $this->assertSame(403, $this->codigo());
            $this->assertSame(1, $this->totalDeArquivos($raiz));
        });
    }

    public function testAntesDaPublicacaoNadaEAnexado(): void
    {
        $this->noEdital(function (Opportunity $raiz) {
            $payload = $this->anexa($raiz, 30, $this->upload('ata.pdf'));

            $this->assertSame([400, 'Publique o resultado final do edital para preencher o formulário.'], [$this->codigo(), $payload['data']]);
            $this->assertSame(0, $this->totalDeArquivos($raiz));
        }, publicado: false);
    }

    public function testEditalSemFormularioNaoRecebeNada(): void
    {
        $this->noEdital(function (Opportunity $raiz) {
            FakeIntegrationProvider::$aoBuscarFormulario = fn() => null;

            foreach ([$this->anexa($raiz, 30, $this->upload('ata.pdf')), $this->salva($raiz, [10 => 'texto'])] as $payload) {
                $this->assertSame([400, 'Este edital não tem formulário de resultado no CultBR.'], [$this->codigo(), $payload['data']]);
            }
        });
    }

    public function testOrigemForaDoArResponde502(): void
    {
        $this->noEdital(function (Opportunity $raiz) {
            FakeIntegrationProvider::$aoBuscarFormulario = fn() => throw IntegrationError::transport('Connection timed out', 28);

            $this->anexa($raiz, 30, $this->upload('ata.pdf'));

            $this->assertSame(502, $this->codigo());
        });
    }

    public function testAbaDeVersaoAnteriorRecebeOFormularioVigente(): void
    {
        $this->noEdital(function (Opportunity $raiz) {
            $id = $this->anexa($raiz, 30, $this->upload('ata.pdf'), ['id_formulario_versao' => (string) self::VERSAO])['anexos'][30][0]['id'];

            $payload = $this->anexa($raiz, 31, $this->upload('parecer.pdf'), ['id_formulario_versao' => '100']);
            $this->assertSame([200, true, self::VERSAO], [$this->codigo(), $payload['versaoAlterada'], $payload['estrutura']['id_formulario_versao']]);

            $this->assertTrue($this->retira($raiz, 30, $id, ['id_formulario_versao' => 100])['versaoAlterada']);
            $this->assertSame(1, $this->totalDeArquivos($raiz));
        });
    }

    #[DataProvider('envios')]
    public function testEnvioSemArquivoUtilDizOMotivo(?array $arquivo, int $tamanhoDoCorpo, string $motivo): void
    {
        $this->noEdital(function (Opportunity $raiz) use ($arquivo, $tamanhoDoCorpo, $motivo) {
            $_SERVER['CONTENT_LENGTH'] = $tamanhoDoCorpo;

            $payload = $this->anexa($raiz, 30, $arquivo);

            $this->assertSame([400, $motivo], [$this->codigo(), $payload['data']]);
        });
    }

    public static function envios(): array
    {
        $limite = \MapasCulturais\App::i()->getMaxUploadSize(false) * 1024;
        $recebido = ['name' => 'ata.pdf', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0];

        return [
            'sem arquivo' => [null, 10, 'Selecione um arquivo.'],
            'corpo no limite' => [null, $limite, 'Selecione um arquivo.'],
            'corpo descartado pelo tamanho' => [null, $limite + 1, 'O arquivo enviado é maior do que o permitido.'],
            'arquivo acima do limite do servidor' => [$recebido, 10, 'O arquivo enviado é maior do que o permitido.'],
            'vários no mesmo campo' => [['name' => ['a.pdf', 'b.pdf']] + $recebido, 10, 'Selecione um arquivo.'],
        ];
    }

    public function testFalhaAoGravarNoDiscoVoltaComOMotivo(): void
    {
        $this->noEdital(function (Opportunity $raiz) {
            $isDiscoCheio = true;
            $this->app->hook('entity(<<*>>Opportunity).file(cultBrResultadoDocumento).insert:before', function () use (&$isDiscoCheio) {
                if ($isDiscoCheio) {
                    throw new SaveFileError('Não foi possível salvar o arquivo');
                }
            });

            $payload = $this->anexa($raiz, 30, $this->upload('ata.pdf'));
            $isDiscoCheio = false;

            $this->assertSame([400, 'Não foi possível salvar o arquivo'], [$this->codigo(), $payload['data']]);
        });
    }

    public function testSalvarOFormularioNaoAlteraOsArquivos(): void
    {
        $this->noEdital(function (Opportunity $raiz) {
            $anexos = $this->anexa($raiz, 30, $this->upload('ata.pdf'))['anexos'];

            $payload = $this->salva($raiz, [10 => 'texto', 30 => null, 31 => [$anexos[30][0]['id']]]);

            $this->assertSame([$anexos, [10 => 'texto'], []], [$payload['anexos'], $payload['respostas'], $payload['erros']]);
            $this->assertSame(1, $this->totalDeArquivos($raiz));
        });
    }

    public function testDocumentoRequeridoEPendenciaAteSerAnexado(): void
    {
        $this->noEdital(function (Opportunity $raiz) {
            $this->assertSame([[32, 'anexe o documento']], array_map(
                fn(array $pendencia) => [$pendencia['id_questao'], $pendencia['motivo']],
                $this->salva($raiz, [10 => 'texto'])['pendencias'],
            ));

            $payload = $this->anexa($raiz, 32, $this->upload('ata.pdf'));
            $this->assertSame([[], true], [$payload['pendencias'], $payload['envioHabilitado']]);

            $this->assertFalse($this->retira($raiz, 32, $payload['anexos'][32][0]['id'])['envioHabilitado']);
        }, documentos: [$this->questaoDeDocumento(32, [], ['requerida' => true])]);
    }

    public function testPlanilhaComArquivoAnexadoBloqueiaAteOArquivoSair(): void
    {
        $this->noEdital(function (Opportunity $raiz) {
            FakeIntegrationProvider::$aoBuscarModelo = fn() => ['possui_planilha' => false];
            $anexado = $this->anexa($raiz, 30, $this->upload('ata.pdf'));

            FakeIntegrationProvider::$aoBuscarModelo = fn() => ['possui_planilha' => true];
            $this->assertSame(FormularioResultadoService::motivoDocumentoComPlanilha(), $this->anexa($raiz, 30, $this->upload('outra.pdf'))['data'], 'com planilha, nenhum arquivo novo entra');

            $payload = $this->salva($raiz, [10 => 'texto']);
            $payload['anexos'] = $anexado['anexos'];
            $this->assertSame(
                [['id_questao' => null, 'nome' => '', 'motivo' => FormularioResultadoService::motivoPlanilhaComAnexos()]],
                $payload['bloqueios'],
            );
            $this->assertFalse($payload['envioHabilitado']);

            $recusa = $this->chama('callPostResultadoEnviar', ['opportunityId' => $raiz->id]);
            $this->assertSame([400, $payload['bloqueios']], [$this->codigo(), $recusa['data']['bloqueios']]);

            $this->assertTrue($this->retira($raiz, 30, $payload['anexos'][30][0]['id'])['envioHabilitado']);
        });
    }

    public function testSemPlanilhaOArquivoNaoBloqueia(): void
    {
        $this->noEdital(function (Opportunity $raiz) {
            FakeIntegrationProvider::$aoBuscarModelo = fn() => ['possui_planilha' => false];
            $this->salva($raiz, [10 => 'texto']);

            $this->assertTrue($this->anexa($raiz, 30, $this->upload('ata.pdf'))['envioHabilitado']);
        });
    }

    public function testArquivoDeQuestaoEscondidaNaoBloqueiaNemCompleta(): void
    {
        $regra = ['tipo' => 'visibilidade', 'efeito' => ['mostrar' => true], 'quando' => ['operador' => 'eq', 'valor' => 'sim', 'fonteIdQuestaoEtapa' => 900]];

        $this->noEdital(function (Opportunity $raiz) {
            FakeIntegrationProvider::$aoBuscarModelo = fn() => ['possui_planilha' => false];
            $this->salva($raiz, [10 => 'sim']);
            $this->anexa($raiz, 33, $this->upload('ata.pdf'));

            FakeIntegrationProvider::$aoBuscarModelo = fn() => ['possui_planilha' => true];
            $this->assertFalse($this->salva($raiz, [10 => 'sim'])['envioHabilitado'], 'o arquivo da questão visível bloqueia com planilha');
            $this->assertTrue($this->salva($raiz, [10 => 'não'])['envioHabilitado']);
        }, documentos: [$this->questaoDeDocumento(33, ['regras' => ['itens' => [$regra]]])]);
    }
}
