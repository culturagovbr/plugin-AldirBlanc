<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Enum\EnvioSituacao;
use AldirBlanc\Exceptions\IntegrationError;
use AldirBlanc\Jobs\ResultadoCultJob;
use AldirBlanc\Services\FormularioResultadoService;
use AldirBlanc\Services\ResultadoService;
use Laminas\Diactoros\Response;
use MapasCulturais\Entities\Job;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Exceptions\Halt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\FakeIntegrationProvider;
use Tests\AldirBlanc\Doubles\TestableController;
use Tests\AldirBlanc\Traits\BuildsContempladas;
use Tests\AldirBlanc\Traits\BuildsPublishedFinalPhase;
use Tests\AldirBlanc\Traits\ConfiguresPlugin;
use Tests\AldirBlanc\Traits\IsolatesJobQueue;
use Tests\Traits\UserDirector;

/** POST /aldirblanc/resultadoEnviar — o botão do gestor, único caminho do primeiro envio ao CultBR. */
class ControllerResultadoEnviarTest extends TestCase
{
    use UserDirector;
    use IsolatesJobQueue;
    use BuildsContempladas;
    use BuildsPublishedFinalPhase;
    use ConfiguresPlugin;

    /** Estrutura com uma questão obrigatória: o formulário nunca completa. */
    private const ESTRUTURA_INCOMPLETA = ['id_formulario' => 110, 'id_formulario_versao' => 1, 'versao' => 1, 'secoes' => [['etapas' => [['questoes' => [
        ['id_questao' => 52, 'nome' => 'Comentários', 'tipo_questao' => 'textarea', 'requerida' => true],
    ]]]]]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearJobQueue();
    }

    /** Edital integrado, com a fase final publicada ou não, e o gestor logado. */
    private function cenario(string $nome, bool $publicada = true, bool $comIdDoPar = true): Opportunity
    {
        $owner = $this->userDirector->createUser();
        $raiz = $this->raizElegivel($owner, $this->integrationSubsite($owner, $nome));
        $this->faseFinal($raiz, publicada: $publicada);

        if ($comIdDoPar) {
            $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, '53790');
        }

        $this->login($owner);
        $this->clearJobQueue();

        return $raiz;
    }

    private function enviar(Opportunity $raiz): array
    {
        $controller = new TestableController();
        $controller->data = ['opportunityId' => $raiz->id];

        $this->app->response = new Response();

        try {
            $controller->callPostResultadoEnviar();
        } catch (Halt) {
        }

        return json_decode((string) $this->app->response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function jobsDeResultado(): array
    {
        return $this->app->repo(Job::class)->findBy(['type' => ResultadoCultJob::SLUG]);
    }

    function testBotaoEnfileiraOPrimeiroEnvio()
    {
        $this->comFormularioResultado(function () {
            $raiz = $this->cenario('Subsite Enviar Feliz');

            $payload = $this->enviar($raiz);

            $this->assertSame(200, $this->app->response->getStatusCode());
            $this->assertTrue($payload['enfileirado']);
            $this->assertCount(1, $this->jobsDeResultado(), 'o botão é o único caminho do primeiro envio');
        });
    }

    function testBotaoAcionadoDeNovoEnfileiraORetificado()
    {
        $this->comFormularioResultado(function () {
            $raiz = $this->cenario('Subsite Enviar Retificado');
            $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_RESULTADO_ID, '777');

            $this->enviar($raiz);

            $this->assertCount(1, $this->jobsDeResultado(), 'resultado já publicado é imutável: o reenvio sai como retificado');
        });
    }

    function testIdDeFaseEnviaPelaRaiz()
    {
        $this->comFormularioResultado(function () {
            $raiz = $this->cenario('Subsite Enviar Por Fase');
            $faseFinal = $raiz->lastPhase;

            $controller = new TestableController();
            $controller->data = ['opportunityId' => $faseFinal->id];
            $this->app->response = new Response();
            try {
                $controller->callPostResultadoEnviar();
            } catch (Halt) {
            }

            $tipo = $this->app->getRegisteredJobType(ResultadoCultJob::SLUG);
            $jobs = $this->jobsDeResultado();

            $this->assertCount(1, $jobs, 'o gestor pode chegar pelo id de uma fase');
            $this->assertSame(
                $tipo->generateId(['opportunity' => $raiz], '', '', 1),
                $jobs[0]->id,
                'a fase daria outro id de job e montaria o corpo de outro edital'
            );
        });
    }

    function testFaseFinalNaoPublicadaRecusa()
    {
        $this->comFormularioResultado(function () {
            $raiz = $this->cenario('Subsite Enviar Nao Publicada', publicada: false);

            $payload = $this->enviar($raiz);

            $this->assertSame(400, $this->app->response->getStatusCode());
            $this->assertStringContainsString('Publique', (string) $payload['data']);
            $this->assertCount(0, $this->jobsDeResultado());
        });
    }

    function testEditalSemIdDoParRecusa()
    {
        $this->comFormularioResultado(function () {
            $raiz = $this->cenario('Subsite Enviar Sem Par', comIdDoPar: false);

            $this->enviar($raiz);

            $this->assertSame(400, $this->app->response->getStatusCode());
            $this->assertCount(0, $this->jobsDeResultado());
        });
    }

    public function testOrigemForaDoArRecusaSemListaDoQueFalta(): void
    {
        $this->comFormularioResultado(function () {
            $raiz = $this->cenario('Subsite Enviar Origem Fora');
            FakeIntegrationProvider::$aoBuscarFormulario = fn() => throw IntegrationError::transport('Connection timed out', 28);

            $payload = $this->enviar($raiz);

            $this->assertSame(400, $this->app->response->getStatusCode());
            $this->assertSame(['message'], array_keys($payload['data']));
            $this->assertCount(0, $this->jobsDeResultado());
        });
    }

    #[DataProvider('planilhasQueImpedem')]
    public function testPlanilhaNaoResolvidaRecusaComOMotivo(callable $modelo, string $motivo): void
    {
        $this->comFormularioResultado(function () use ($modelo, $motivo) {
            FakeIntegrationProvider::$aoBuscarModelo = $modelo;
            $raiz = $this->cenario('Subsite Enviar Planilha ' . uniqid());

            $payload = $this->enviar($raiz);

            $this->assertSame(400, $this->app->response->getStatusCode());
            $this->assertStringContainsString($motivo, $payload['data']['bloqueios'][0]['motivo']);
            $this->assertCount(0, $this->jobsDeResultado());
        });
    }

    public static function planilhasQueImpedem(): array
    {
        return [
            'modelo indisponível' => [fn() => throw IntegrationError::transport('Connection timed out', 28), 'Não foi possível consultar'],
        ];
    }

    public function testPendenciaDaPlanilhaNaoImpedeOPedido(): void
    {
        $this->comFormularioResultado(function () {
            FakeIntegrationProvider::$aoBuscarModelo = fn() => $this->respostaDaConecta('modelo-pontos-de-cultura');
            $raiz = $this->cenario('Subsite Enviar Com Modelo');
            $this->contempladaComDocumento($raiz, $raiz->lastPhase, '111.444.777-35');
            $this->clearJobQueue();

            $this->enviar($raiz);

            $this->assertSame(200, $this->app->response->getStatusCode());
            $this->assertCount(1, $this->jobsDeResultado());
        });
    }

    public function testEditalSemModeloEnvia(): void
    {
        $this->comFormularioResultado(function () {
            FakeIntegrationProvider::$aoBuscarModelo = fn() => ['possui_planilha' => false, 'motivo' => 'A ação 1.2 não possui modelo de planilha'];
            $raiz = $this->cenario('Subsite Enviar Sem Modelo');

            $this->enviar($raiz);

            $this->assertCount(1, $this->jobsDeResultado());
        });
    }

    private function gravaUltimoEnvio(Opportunity $raiz, EnvioSituacao $situacao, int $segundos): void
    {
        (new ResultadoService())->persistUltimoEnvio((int) $raiz->id, $situacao, [
            'contadoDe' => (new \DateTime("-{$segundos} seconds"))->format(\DateTime::ATOM),
        ]);
    }

    #[DataProvider('situacoesSemDesfecho')]
    public function testEnvioSemDesfechoDentroDoPrazoRecusaComAHora(EnvioSituacao $situacao): void
    {
        $this->comFormularioResultado(function () use ($situacao) {
            $raiz = $this->cenario('Subsite Enviar No Prazo ' . uniqid());
            $this->gravaUltimoEnvio($raiz, $situacao, 60);

            $payload = $this->enviar($raiz);

            $this->assertSame(409, $this->app->response->getStatusCode());
            $hora = (new \DateTime('+' . (ResultadoService::PRAZO_DO_ENVIO_EM_ANDAMENTO - 60) . ' seconds'))->format('H:i');
            $this->assertStringContainsString($hora, $payload['data']);
            $this->assertCount(0, $this->jobsDeResultado());
        });
    }

    #[DataProvider('situacoesSemDesfecho')]
    public function testDepoisDoPrazoOPedidoEAceito(EnvioSituacao $situacao): void
    {
        $this->comFormularioResultado(function () use ($situacao) {
            $raiz = $this->cenario('Subsite Enviar Vencido ' . uniqid());
            $this->gravaUltimoEnvio($raiz, $situacao, ResultadoService::PRAZO_DO_ENVIO_EM_ANDAMENTO + 1);

            $this->assertSame('sem-confirmacao', (new ResultadoService())->ultimoEnvio((int) $raiz->id)['situacao']);

            $this->enviar($raiz);

            $this->assertCount(1, $this->jobsDeResultado());
            $ultimo = (new ResultadoService())->ultimoEnvio((int) $raiz->id);
            $this->assertSame('aguardando', $ultimo['situacao']);
            $this->assertNotNull($ultimo['novoPedidoEm'], 'o novo pedido abre o seu próprio prazo');
        });
    }

    public static function situacoesSemDesfecho(): array
    {
        return [
            'aguardando' => [EnvioSituacao::Aguardando],
            'enviando' => [EnvioSituacao::Enviando],
            'sem confirmação' => [EnvioSituacao::SemConfirmacao],
        ];
    }

    #[DataProvider('situacoesComDesfecho')]
    public function testEnvioComDesfechoNaoImpedeONovoPedido(EnvioSituacao $situacao): void
    {
        $this->comFormularioResultado(function () use ($situacao) {
            $raiz = $this->cenario('Subsite Enviar Desfecho ' . uniqid());
            $this->gravaUltimoEnvio($raiz, $situacao, 1);

            $this->enviar($raiz);

            $this->assertCount(1, $this->jobsDeResultado());
        });
    }

    public static function situacoesComDesfecho(): array
    {
        return array_map(fn(EnvioSituacao $situacao) => [$situacao], [
            EnvioSituacao::Publicado, EnvioSituacao::Simulado, EnvioSituacao::NadaMudou, EnvioSituacao::Recusado, EnvioSituacao::NaoEnviado,
        ]);
    }

    public function testSituacaoCopiadaDeOutraOportunidadeEIgnorada(): void
    {
        $this->comFormularioResultado(function () {
            $raiz = $this->cenario('Subsite Enviar Copiado');
            $this->gravaUltimoEnvio($raiz, EnvioSituacao::Enviando, 60);
            $this->app->em->getConnection()->executeStatement(
                'UPDATE opportunity_meta SET value = replace(value, :de, :para) WHERE object_id = :id AND key = :key',
                ['de' => '"oportunidade":' . $raiz->id, 'para' => '"oportunidade":1', 'id' => $raiz->id, 'key' => Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_ULTIMO_ENVIO],
            );

            $this->assertNull((new ResultadoService())->ultimoEnvio((int) $raiz->id));

            $this->enviar($raiz);

            $this->assertCount(1, $this->jobsDeResultado());
        });
    }

    public function testPedidoNovoGuardaOEnvioAConferir(): void
    {
        $this->comFormularioResultado(function () {
            $raiz = $this->cenario('Subsite Enviar Guarda');
            $em = (new \DateTime('-1 hour'))->format(\DateTime::ATOM);
            (new ResultadoService())->persistUltimoEnvio((int) $raiz->id, EnvioSituacao::SemConfirmacao, [
                'contadoDe' => $em, 'aConferir' => ['idsAntes' => [20010], 'hash' => 'x', 'tipo' => 2, 'em' => $em],
            ]);

            $this->enviar($raiz);

            $this->assertSame([20010], (new ResultadoService())->envioAConferir((int) $raiz->id)['idsAntes']);
        });
    }

    public function testExecucaoImediataNaoTemODesfechoSobrescrito(): void
    {
        $this->comFormularioResultado(function () {
            $raiz = $this->cenario('Subsite Enviar Imediato');
            FakeIntegrationProvider::$aoEnviarResultado = fn() => throw IntegrationError::http('recusado', 422);
            $original = $this->app->config['app.executeJobsImmediately'] ?? null;
            $this->app->config['app.executeJobsImmediately'] = true;

            try {
                $this->enviar($raiz);
            } finally {
                $this->app->config['app.executeJobsImmediately'] = $original;
            }

            $this->assertSame('recusado', (new ResultadoService())->ultimoEnvio((int) $raiz->id)['situacao']);
        });
    }

    public function testAcaoQueNaoRecebeResultadoRecusaComOMotivo(): void
    {
        $this->comFormularioResultado(function () {
            FakeIntegrationProvider::$aoBuscarPermissao = fn() => false;
            $raiz = $this->cenario('Subsite Enviar Acao Sem Resultado');

            $payload = $this->enviar($raiz);

            $this->assertSame(400, $this->app->response->getStatusCode());
            $this->assertSame(FormularioResultadoService::motivoAcaoSemResultado(), $payload['data']);
            $this->assertCount(0, $this->jobsDeResultado());
        });
    }

    #[DataProvider('permissoesQueNaoImpedem')]
    public function testSemNegativaDaOrigemOEnvioSai(callable $permissao): void
    {
        $this->comFormularioResultado(function () use ($permissao) {
            FakeIntegrationProvider::$aoBuscarPermissao = $permissao;
            $raiz = $this->cenario('Subsite Enviar Permissao ' . uniqid());

            $this->enviar($raiz);

            $this->assertCount(1, $this->jobsDeResultado());
        });
    }

    public static function permissoesQueNaoImpedem(): array
    {
        return [
            'permitido' => [fn() => true],
            'desconhecido' => [fn() => null],
            'leitura com falha' => [fn() => throw IntegrationError::transport('Connection timed out', 28)],
        ];
    }

    public function testFormularioIncompletoRecusaDizendoOQueFalta(): void
    {
        $this->comFormularioResultado(function () {
            $raiz = $this->cenario('Subsite Enviar Incompleto');

            $payload = $this->enviar($raiz);

            $this->assertSame(400, $this->app->response->getStatusCode());
            $this->assertStringContainsString('Complete o formulário', $payload['data']['message']);
            $this->assertSame(
                [['id_questao' => 52, 'nome' => 'Comentários', 'motivo' => 'resposta obrigatória']],
                $payload['data']['pendencias'],
            );
            $this->assertSame([], $payload['data']['bloqueios']);
            $this->assertCount(0, $this->jobsDeResultado(), 'sem formulário completo o envio tomaria 422 do validador');
        }, self::ESTRUTURA_INCOMPLETA);
    }
}
