<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Plugin;
use AldirBlanc\Services\ResultadoService;
use MapasCulturais\Entities\Opportunity;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\FakeIntegrationProvider;
use Tests\AldirBlanc\Traits\BuildsContempladas;
use Tests\AldirBlanc\Traits\BuildsPublishedFinalPhase;
use Tests\AldirBlanc\Traits\ConfiguresPlugin;
use Tests\AldirBlanc\Traits\LoadsConectaResponses;
use Tests\Traits\UserDirector;

/** Régua e payload do resultado: a fase final publicada decide, nunca o flag da raiz. */
class ResultadoServiceTest extends TestCase
{
    use UserDirector;
    use BuildsPublishedFinalPhase;
    use BuildsContempladas;
    use ConfiguresPlugin;
    use LoadsConectaResponses;

    private function service(): ResultadoService
    {
        return new ResultadoService();
    }

    function testFaseFinalPublicadaDeRaizCompletaEhElegivel()
    {
        [, $fase] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->assertTrue($this->service()->isEligibleForResultSync($fase));
    }

    function testFaseFinalNaoPublicadaNaoEhElegivel()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Nao Publicada');
        $raiz = $this->raizElegivel($owner, $subsite);
        $fase = $this->faseFinal($raiz, publicada: false);
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, '53790');

        $this->assertFalse($this->service()->isEligibleForResultSync($fase));
    }

    /** Fase intermediária e fase de recurso caem neste ramo: sem isLastPhase não há envio. */
    function testFaseQueNaoEhAFinalNaoEhElegivel()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Intermediaria');
        $raiz = $this->raizElegivel($owner, $subsite);
        $fase = $this->faseFinal($raiz);
        $fase->setMetadata('isLastPhase', false);
        $this->app->disableAccessControl();
        $fase->save(true);
        $this->app->enableAccessControl();
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, '53790');

        $this->assertFalse($this->service()->isEligibleForResultSync($fase));
    }

    function testRaizSemIdDoParNaoEhElegivel()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Sem Par Id');
        $raiz = $this->raizElegivel($owner, $subsite);
        $fase = $this->faseFinal($raiz);

        $this->assertFalse($this->service()->isEligibleForResultSync($fase));
    }

    function testRaizReprovadaNaReguaDaFase1NaoEhElegivel()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Sem Ente');
        $raiz = $this->raizElegivel($owner, $subsite);
        $fase = $this->faseFinal($raiz);
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, '53790');

        $this->app->disableAccessControl();
        $raiz->setMetadata('federativeEntityId', '');
        $raiz->save(true);
        $this->app->enableAccessControl();

        $this->assertFalse($this->service()->isEligibleForResultSync($fase));
    }

    function testPrimeiroEnvioMontaFinalPublicadoComADataDaFase()
    {
        [, $fase] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->comFormularioResultado(function () use ($fase) {
            $payload = $this->service()->buildResultado($fase)->toArray();

            // Literais de propósito: os valores são contrato do PAR, e comparar com o enum seria tautologia.
            $this->assertSame(2, $payload['id_tipo']);
            $this->assertSame(3, $payload['id_status']);
            $this->assertStringStartsWith('2026-10-10T12:00:00', $payload['data_publicacao'], 'o final carrega a data da publicação no Mapas');
        });
    }

    private function contempladaCompleta(Opportunity $raiz, Opportunity $fase): void
    {
        $this->app->disableAccessControl();
        $raiz->registrationRanges = [['label' => 'Faixa A', 'value' => '15000']];
        $raiz->save(true);
        $this->app->enableAccessControl();

        $this->contempladaComDocumento($raiz, $fase, '111.444.777-35', 'Pessoa Física', 'Faixa A');
    }

    public function testCorpoNaoLevaAPlanilhaNoFormatoAntigo(): void
    {
        [$raiz, $fase] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->contempladaCompleta($raiz, $fase);

        $this->comFormularioResultado(function () use ($fase) {
            $payload = $this->service()->buildResultado($fase)->toArray();

            $this->assertSame(['id_tipo', 'id_status', 'data_publicacao', 'respostas'], array_keys($payload));
        });
    }

    function testComResultadoVigenteMontaRetificadoDatadoDaRetificacao()
    {
        [$raiz, $fase] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_RESULTADO_ID, '777');

        $this->comFormularioResultado(function () use ($fase) {
            $payload = $this->service()->buildResultado($fase)->toArray();

            $this->assertSame(3, $payload['id_tipo']);
            $this->assertSame(3, $payload['id_status']);
            $this->assertStringNotContainsString('2026-10-10', $payload['data_publicacao'], 'a data original dataria o retificado antes de ele existir');
        });
    }

    function testPublicacaoSemCarimboCaiParaOInstanteDoEnvio()
    {
        $owner = $this->userDirector->createUser();
        $subsite = $this->integrationSubsite($owner, 'Subsite Sem Carimbo');
        $raiz = $this->raizElegivel($owner, $subsite);
        $fase = $this->faseFinal($raiz, publicada: true, publicadaEm: null);
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_ID, '53790');

        $this->comFormularioResultado(function () use ($fase) {
            $payload = $this->service()->buildResultado($fase)->toArray();

            $this->assertNotNull($payload['data_publicacao'], 'resultado publicado nunca viaja sem data');
        });
    }

    /** Respostas como a tela as grava para o corpo aceito pela homologação. */
    private const RESPOSTAS_GRAVADAS = [
        2186 => 'fe_tec',
        1840 => 15000,
        1842 => ['heteroidentificacao'],
        1843 => true,
        2280 => '2026-12-15',
        2965 => 'Execução realizada conforme o planejado.',
        2654 => [['nome' => 'Ana Paula Ferreira', 'cpf' => '529.982.247-25', 'vinculo' => 'servidor público', 'categoria_grupo' => 'Todas as propostas']],
        2081 => ['declaracao_integral'],
    ];

    /** Corpo das respostas, por id de envio, com as respostas gravadas na estrutura da 1.1. */
    private function corpoDa11(array $trocas = [], ?callable $preparo = null): array
    {
        [$raiz, $fase] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_FORM, json_encode([
            'id_formulario' => 109, 'id_formulario_versao' => 541, 'versao' => 12, 'respostas' => array_replace(self::RESPOSTAS_GRAVADAS, $trocas),
        ]));
        $corpo = [];

        $this->comFormularioResultado(function () use (&$corpo, $raiz, $fase, $preparo) {
            if ($preparo !== null) {
                $preparo($raiz, $fase);
            }

            $corpo = array_column($this->service()->buildResultado($fase)->toArray()['respostas'], 'resposta', 'id_par_questao');
        }, $this->respostaDaConecta('estrutura-tipo-1'));

        return array_map(fn(array $resposta) => $resposta['resposta'], $corpo);
    }

    public function testCorpoSaiComoOAceitoPelaHomologacao(): void
    {
        $this->assertSame([
            13915 => 'Termo de Execução Cultural (Lei nº 14.903/2024)',
            13918 => 0,
            13919 => 0,
            13920 => 15000,
            13921 => ['Heteroidentificação para pessoas negras'],
            13923 => true,
            13924 => '2026-12-15',
            13925 => 'Execução realizada conforme o planejado.',
            13933 => [['nome' => 'Ana Paula Ferreira', 'cpf' => '529.982.247-25', 'vinculo' => 'servidor público', 'categoria_grupo' => 'Todas as propostas']],
            13934 => ['Declaro, sob as penas da legislação em vigor, que as informações prestadas são verdadeiras, que a execução dos recursos está em conformidade com as regras e os normativos da Política Nacional Aldir Blanc de Fomento à Cultura e que este edital, bem como os demais procedimentos a ele relacionados, observa o Manual de Marcas disponibilizado pelo Ministério da Cultura, nos termos do art. 22 do Decreto nº 11.740/2023.'],
        ], $this->corpoDa11());
    }

    public function testOpcaoComplementadaViajaComORotuloEOTexto(): void
    {
        $corpo = $this->corpoDa11([
            2186 => ['id' => 'fe_outros', 'complemento' => 'Termo de cessão de uso'],
            1842 => [['id' => 'outras_estrategias', 'complemento' => 'Banca de verificação documental']],
        ]);

        $this->assertSame('Outros: Termo de cessão de uso', $corpo[13915]);
        $this->assertSame(['Outras estratégias (especificar): Banca de verificação documental'], $corpo[13921]);
    }

    public function testTabelaCalculadaViajaComNumerosJson(): void
    {
        $corpo = $this->corpoDa11(preparo: function (Opportunity $raiz, Opportunity $fase) {
            $this->app->disableAccessControl();
            $raiz->registrationRanges = [['label' => 'Música', 'limit' => 1, 'value' => '10000'], ['label' => 'Dança', 'limit' => 2, 'value' => 5001]];
            $raiz->save(true);
            $this->app->enableAccessControl();
        });

        $this->assertSame([
            ['categoria' => 'Música', 'quantidade_contemplados' => 0, 'valor_individual' => 10000],
            ['categoria' => 'Dança', 'quantidade_contemplados' => 0, 'valor_individual' => 2500.5],
        ], $corpo[13922]);
    }

    public function testFaixaSemValorViajaComACelulaVazia(): void
    {
        $corpo = $this->corpoDa11(preparo: function (Opportunity $raiz) {
            $this->app->disableAccessControl();
            $raiz->registrationRanges = [['label' => 'Música', 'limit' => 1, 'value' => null]];
            $raiz->save(true);
            $this->app->enableAccessControl();
        });

        $this->assertSame([['categoria' => 'Música', 'quantidade_contemplados' => 0, 'valor_individual' => null]], $corpo[13922]);
    }

    public function testNumeroGravadoComoTextoViajaComoNumero(): void
    {
        $this->assertSame(15000.5, $this->corpoDa11([1840 => '15000.50'])[13920]);
        $this->assertSame(15000, $this->corpoDa11([1840 => '15000.00'])[13920]);
        $this->assertSame(15000, $this->corpoDa11([1840 => 15000.0])[13920]);
    }

    #[DataProvider('respostasQueFicamFora')]
    public function testRespostaQueNaoServeFicaForaDoCorpo(array $trocas, int $idEnvio): void
    {
        $this->assertArrayNotHasKey($idEnvio, $this->corpoDa11($trocas));
    }

    public static function respostasQueFicamFora(): array
    {
        return [
            'invisível pela regra' => [[1845 => 'Comentário do não, guardado.'], 13926],
            'inválida' => [[2280 => '15/12/2026'], 13924],
            'incompleta' => [[2186 => 'fe_outros'], 13915],
            'sem marcação' => [[2081 => []], 13934],
            'vazia' => [[2965 => ''], 13925],
            'somente leitura' => [[2187 => 'Edital 1'], 13916],
            'anexo' => [[2079 => 'arquivo.pdf'], 13927],
            'planilha' => [[2077 => 'planilha.csv'], 13930],
        ];
    }

    public function testCelulaNumericaDaTabelaDoGestorViajaComoNumero(): void
    {
        [$raiz, $fase] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_FORM, json_encode([
            'id_formulario' => 1, 'id_formulario_versao' => 486, 'versao' => 1,
            'respostas' => [1764 => [['tipo_documento' => 'CNPJ', 'numero_documento' => '11222333000181', 'nome_razao_social' => '2026', 'valor_contrato' => '10.50']]],
        ]));

        $this->comFormularioResultado(function () use ($fase) {
            $corpo = array_column($this->service()->buildResultado($fase)->toArray()['respostas'], 'resposta', 'id_par_questao');

            $this->assertSame(
                [['tipo_documento' => 'CNPJ', 'numero_documento' => '11222333000181', 'nome_razao_social' => '2026', 'valor_contrato' => 10.5]],
                $corpo[12516]['resposta'],
                'as demais células viajam como gravadas',
            );
        }, $this->respostaDaConecta('estrutura-tipo-3'));
    }

    public function testTabelaSemLinhaComMinimoZeroViajaVazia(): void
    {
        [$raiz, $fase] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $this->gravaMeta((int) $raiz->id, Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_FORM, json_encode([
            'id_formulario' => 1, 'id_formulario_versao' => 561, 'versao' => 1, 'respostas' => [2843 => []],
        ]));

        $this->comFormularioResultado(function () use ($fase) {
            $corpo = array_column($this->service()->buildResultado($fase)->toArray()['respostas'], 'resposta', 'id_par_questao');

            $this->assertSame([], $corpo[14784]['resposta']);
        }, $this->respostaDaConecta('estrutura-tipo-9'));
    }

    public function testQuestaoCalculadaInvisivelFicaForaDoCorpo(): void
    {
        [, $fase] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $regra = ['tipo' => 'visibilidade', 'efeito' => ['mostrar' => true], 'quando' => ['operador' => 'eq', 'valor' => true, 'fonteIdQuestaoEtapa' => 951]];

        $this->comFormularioResultado(function () use ($fase) {
            $this->assertSame([], $this->service()->buildResultado($fase)->toArray()['respostas']);
        }, ['id_formulario' => 110, 'id_formulario_versao' => 1, 'versao' => 1, 'secoes' => [['etapas' => [['questoes' => [
            ['id_questao' => 50, 'id_questao_etapa' => 950, 'nome' => 'Total', 'tipo_questao' => 'número', 'tag' => 'total_inscritos_1_1', 'config' => ['regras' => ['itens' => [$regra]]]],
            ['id_questao' => 51, 'id_questao_etapa' => 951, 'nome' => 'Houve?', 'tipo_questao' => 'booleano'],
        ]]]]]]);
    }

    public function testQuestaoSemEnderecoNoParFicaForaDoCorpo(): void
    {
        [, $fase] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->comFormularioResultado(function () use ($fase) {
            $this->assertSame([], $this->service()->buildResultado($fase)->toArray()['respostas']);
        }, ['id_formulario' => 110, 'id_formulario_versao' => 1, 'versao' => 1, 'secoes' => [['etapas' => [['questoes' => [
            ['id_questao' => 50, 'nome' => 'Total', 'tipo_questao' => 'número', 'tag' => 'total_inscritos_1_1'],
        ]]]]]]);
    }

    private function jobsDeResultado(): array
    {
        return $this->app->repo('Job')->findBy(['type' => \AldirBlanc\Jobs\ResultadoCultJob::SLUG]);
    }

    function testCompletudeIndisponivelNaoEnfileira()
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        // O provedor da Gestão não serve o formulário: sem certeza da completude, nada é enfileirado.
        $this->comConfigDoPlugin(
            function (array $config) {
                $config['client']['provider'] = 'gestao';

                return $config;
            },
            function () use ($raiz) {
                Plugin::getInstance()->resetIntegrationProvider();

                try {
                    $this->service()->enqueueResultadoSync($raiz);
                } finally {
                    Plugin::getInstance()->resetIntegrationProvider();
                }
            },
        );

        $this->assertCount(0, $this->jobsDeResultado(), 'na dúvida o envio não sai — fail-safe do gate');
    }

    function testEditalSemFormularioNoParNaoEnfileira()
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());

        $this->comFormularioResultado(function () use ($raiz) {
            FakeIntegrationProvider::$aoBuscarTipoAcao = fn() => null;

            $this->service()->enqueueResultadoSync($raiz);

            $this->assertCount(0, $this->jobsDeResultado(), 'sem formulário no PAR a integração não faz nada');
        });
    }

    function testPersistenciaDoIdVigenteSubstituiSemDuplicar()
    {
        [$raiz] = $this->editalComFaseFinalPublicada($this->userDirector->createUser());
        $raizId = (int) $raiz->id;
        $service = $this->service();

        $service->persistParEditalResultadoId($raizId, 777);
        $service->persistParEditalResultadoId($raizId, 888);

        $rows = $this->app->em->getConnection()->fetchAllAssociative(
            'SELECT value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['id' => $raizId, 'key' => Controller::OPPORTUNITY_META_CULT_BR_PAR_EDITAL_RESULTADO_ID]
        );
        $this->assertCount(1, $rows, 'o metadado guarda o VIGENTE: substitui, nunca duplica');
        $this->assertSame('888', $rows[0]['value']);
        $this->assertSame(888, $service->parEditalResultadoId($raizId));
    }
}
