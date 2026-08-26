<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Entities\FederativeEntity;
use AldirBlanc\Enum\MultiselectField;
use AldirBlanc\Enum\SpecialOption;
use MapasCulturais\Entities\Opportunity;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\TestableOpportunityService;
use Tests\Traits\UserDirector;

/**
 * Testes unitários dos helpers de mapeamento em OpportunityService.
 *
 * Cobre normalizeDecimalValue, normalizeDateValue, mapRecursosOutrasFontes,
 * mapReservaVagasCotas, mapTiposFormasInscricao, mapTiposProponentes,
 * mapOutrasModalidadesAcoesAfirmativas, getOpportunityStatus, mapMultiselectToString
 * e getEnteFederadoByOpportunity.
 */
class OpportunityServiceMappingTest extends TestCase
{
    use UserDirector;

    private TestableOpportunityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TestableOpportunityService();
    }

    // ======================= normalizeDecimalValue =======================

    function testNormalizeDecimalValueNullRetornaNul()
    {
        $this->assertNull($this->service->publicNormalizeDecimalValue(null));
    }

    function testNormalizeDecimalValueStringVaziaRetornaNul()
    {
        $this->assertNull($this->service->publicNormalizeDecimalValue(''));
    }

    function testNormalizeDecimalValueStringInteiraRetornaComDuasCasas()
    {
        $this->assertSame('1000.00', $this->service->publicNormalizeDecimalValue('1000'));
    }

    function testNormalizeDecimalValueFormatoBrasileiro()
    {
        // Com vírgula decimal, o '.' é separador de milhar → '1.234,56' → '1234.56'
        $this->assertSame('1234.56', $this->service->publicNormalizeDecimalValue('1.234,56'));
    }

    function testNormalizeDecimalValueSomenteVirgula()
    {
        $this->assertSame('500.00', $this->service->publicNormalizeDecimalValue('500,00'));
    }

    function testNormalizeDecimalValueNaoNumericoRetornaNul()
    {
        $this->assertNull($this->service->publicNormalizeDecimalValue('abc'));
    }

    function testNormalizeDecimalValueIntRetornaFormatado()
    {
        $this->assertSame('5000.00', $this->service->publicNormalizeDecimalValue(5000));
    }

    function testNormalizeDecimalValueZeroRetornaZeroFormatado()
    {
        $this->assertSame('0.00', $this->service->publicNormalizeDecimalValue(0));
    }

    function testNormalizeDecimalValueSomenteEspacosRetornaNul()
    {
        $this->assertNull($this->service->publicNormalizeDecimalValue('   '));
    }

    function testNormalizeDecimalValueStringZeroRetornaZeroFormatado()
    {
        $this->assertSame('0.00', $this->service->publicNormalizeDecimalValue('0'));
    }

    function testNormalizeDecimalValueFloatPreservaCasas()
    {
        $this->assertSame('100698.85', $this->service->publicNormalizeDecimalValue(100698.85));
    }

    function testNormalizeDecimalValueStringDecimalPreservaValor()
    {
        // Metadado float lido de coluna text chega nesta forma.
        $this->assertSame('100698.85', $this->service->publicNormalizeDecimalValue('100698.85'));
    }

    function testNormalizeDecimalValueStringDecimalDeValorAlto()
    {
        $this->assertSame('275892.41', $this->service->publicNormalizeDecimalValue('275892.41'));
    }

    function testNormalizeDecimalValueStringDecimalDeUmaCasaCompletaComZero()
    {
        $this->assertSame('32692.70', $this->service->publicNormalizeDecimalValue('32692.7'));
    }

    function testNormalizeDecimalValueCentavoIsoladoNaoViraReal()
    {
        $this->assertSame('0.01', $this->service->publicNormalizeDecimalValue('0.01'));
    }

    function testNormalizeDecimalValueTresCasasArredonda()
    {
        $this->assertSame('100698.86', $this->service->publicNormalizeDecimalValue('100698.856'));
    }

    function testNormalizeDecimalValueMilharMultiploComCentavos()
    {
        $this->assertSame('1000000.00', $this->service->publicNormalizeDecimalValue('1.000.000,00'));
    }

    function testNormalizeDecimalValueMilharSemVirgula()
    {
        // Sem vírgula, grupos de 3 dígitos são milhar: '1.234' é mil duzentos e trinta e quatro
        $this->assertSame('1234.00', $this->service->publicNormalizeDecimalValue('1.234'));
    }

    function testNormalizeDecimalValueMilharMultiploSemVirgula()
    {
        $this->assertSame('1000000.00', $this->service->publicNormalizeDecimalValue('1.000.000'));
    }

    function testNormalizeDecimalValueIgnoraEspacosNasBordas()
    {
        $this->assertSame('100698.85', $this->service->publicNormalizeDecimalValue('  100698.85  '));
    }

    function testNormalizeDecimalValueMilharComEspacosNasBordas()
    {
        $this->assertSame('1234.00', $this->service->publicNormalizeDecimalValue('  1.234  '));
    }

    function testNormalizeDecimalValueNegativoPassaAdiante()
    {
        $this->assertSame('-500.00', $this->service->publicNormalizeDecimalValue('-500.00'));
    }

    function testNormalizeDecimalValueComSimboloMonetarioRetornaNul()
    {
        $this->assertNull($this->service->publicNormalizeDecimalValue('R$ 100,00'));
    }

    function testNormalizeDecimalValueCobreOsFormatosDeValorMonetario()
    {
        $casos = [
            // inteiro e decimal com ponto
            ['250', '250.00'], ['100698.85', '100698.85'], ['32692.7', '32692.70'], ['0.01', '0.01'],
            ['275892.41', '275892.41'], ['653880.7', '653880.70'], ['1862.19', '1862.19'],
            ['206049.8', '206049.80'], ['0', '0.00'], ['1000000', '1000000.00'], ['47011.05', '47011.05'],
            ['11573.28', '11573.28'], ['9992.87', '9992.87'], ['24611.6', '24611.60'],
            ['195090.56', '195090.56'],

            // brasileiro: vírgula decimal, ponto de milhar
            ['1.234,56', '1234.56'], ['1.000.000,00', '1000000.00'], ['100.698,85', '100698.85'],
            ['500,00', '500.00'], ['0,01', '0.01'], ['12,5', '12.50'], ['1.234.567,89', '1234567.89'],
            ['999,99', '999.99'], ['1.500,75', '1500.75'], ['25.000,00', '25000.00'],
            ['999.999,99', '999999.99'], ['123,456', '123.46'],

            // só separador de milhar
            ['1.234', '1234.00'], ['1.000.000', '1000000.00'], ['100.698', '100698.00'],
            ['12.500', '12500.00'], ['999.999', '999999.00'], ['1.000', '1000.00'], ['10.000', '10000.00'],
            ['100.000', '100000.00'], ['1.005', '1005.00'], ['7.005', '7005.00'],

            // negativos
            ['-500.00', '-500.00'], ['-1.234', '-1234.00'], ['-1.234,56', '-1234.56'], ['-0.01', '-0.01'],
            ['-1000', '-1000.00'], ['-100698.85', '-100698.85'], ['-1.000.000,00', '-1000000.00'],
            ['-0,50', '-0.50'], ['-1,234.56', '-1234.56'], ['-32692.7', '-32692.70'],

            // zero à esquerda nunca é milhar
            ['0.005', '0.01'], ['0.004', '0.00'], ['0.999', '1.00'], ['0.001', '0.00'], ['0.500', '0.50'],
            ['00100.50', '100.50'],

            // espaços nas bordas
            ['  100698.85  ', '100698.85'], ['  1.234  ', '1234.00'], ['  1.234,56  ', '1234.56'],
            ['   ', null], [' 250 ', '250.00'], ['  0,01  ', '0.01'], ['  1,234.56  ', '1234.56'],

            // arredondamento na terceira casa
            ['100698.856', '100698.86'], ['100698.854', '100698.85'], ['99.999,995', '100000.00'],
            ['1.9999', '2.00'], ['0.1', '0.10'], ['12.3456', '12.35'], ['0.0', '0.00'],

            // não são valor monetário
            ['abc', null], ['R$ 100,00', null], ['', null], [null, null], ['1.234.5', null],
            ['12,34,56', null], ['R$1.000,00', null], ['100,00 reais', null], ['--500', null],
            ['1..234', null], ['1,,234', null], ['-', null],

            // americano: vírgula de milhar, ponto decimal
            ['1,234.56', '1234.56'], ['100,698.85', '100698.85'], ['1,000,000.00', '1000000.00'],
            ['12,500.50', '12500.50'], ['999,999.99', '999999.99'], ['1,234,567.89', '1234567.89'],

            // bordas
            [',50', '0.50'], ['.5', '0.50'], ['+500.00', '500.00'], ['0.10', '0.10'], ['0,10', '0.10'],
            ['1', '1.00'], ['0,001', '0.00'],

            // já numéricos na origem
            [1000, '1000.00'], [0, '0.00'], [100698.85, '100698.85'], [0.01, '0.01'], [-500, '-500.00'],
            [32692.7, '32692.70'], [1000000, '1000000.00'], [-0.01, '-0.01'],
        ];

        foreach ($casos as [$entrada, $esperado]) {
            $this->assertSame(
                $esperado,
                $this->service->publicNormalizeDecimalValue($entrada),
                sprintf('valor %s', var_export($entrada, true))
            );
        }
    }

    // ======================= normalizeDateValue =======================

    function testNormalizeDateValueNullRetornaNul()
    {
        $this->assertNull($this->service->publicNormalizeDateValue(null));
    }

    function testNormalizeDateValueFalseRetornaNul()
    {
        $this->assertNull($this->service->publicNormalizeDateValue(false));
    }

    function testNormalizeDateValueDateTimeRetornaFormatado()
    {
        $dt = new \DateTime('2024-03-15 10:30:00');
        $this->assertSame('2024-03-15 10:30:00', $this->service->publicNormalizeDateValue($dt));
    }

    function testNormalizeDateValueArrayComChaveDateRetornaValor()
    {
        $value = ['date' => '2024-06-01 00:00:00'];
        $this->assertSame('2024-06-01 00:00:00', $this->service->publicNormalizeDateValue($value));
    }

    function testNormalizeDateValueStringRetornaComoEsta()
    {
        $this->assertSame('2024-03-15', $this->service->publicNormalizeDateValue('2024-03-15'));
    }

    // ======================= mapRecursosOutrasFontes =======================

    function testMapRecursosOutrasFontesNullRetornaNul()
    {
        $this->assertNull($this->service->publicMapRecursosOutrasFontes(null));
    }

    function testMapRecursosOutrasFontesNaoArrayRetornaNul()
    {
        $this->assertNull($this->service->publicMapRecursosOutrasFontes('string'));
    }

    function testMapRecursosOutrasFontesRetornaEstruturaCompleta()
    {
        $raw = [
            'houveUtilizacao' => true,
            'recursosProprios' => '1.000,00',
            'conveniosParcerias' => '500,00',
            'emendasParlamentares' => null,
            'remanescentesCiclo1' => null,
            'outrasFontes' => [
                ['nomeFonte' => 'Fonte A', 'valor' => '200,00'],
            ],
        ];

        $result = $this->service->publicMapRecursosOutrasFontes($raw);

        $this->assertTrue($result['houve_utilizacao']);
        $this->assertSame('1000.00', $result['recursos_proprios']);
        $this->assertSame('500.00', $result['convenios_parcerias']);
        $this->assertNull($result['emendas_parlamentares']);
        $this->assertNull($result['remanescentes_ciclo_1']);
        $this->assertIsArray($result['outras_fontes']);
        $this->assertSame('Fonte A', $result['outras_fontes'][0]['nome_fonte']);
        $this->assertSame('200.00', $result['outras_fontes'][0]['valor']);
    }

    function testMapRecursosOutrasFontesOutrasFontesVazioRetornaNulOutrasFontes()
    {
        $raw = ['houveUtilizacao' => false, 'outrasFontes' => []];
        $result = $this->service->publicMapRecursosOutrasFontes($raw);
        $this->assertNull($result['outras_fontes']);
    }

    // ======================= mapReservaVagasCotas =======================

    function testMapReservaVagasCotasNullRetornaNul()
    {
        $this->assertNull($this->service->publicMapReservaVagasCotas(null));
    }

    function testMapReservaVagasCotasArrayVazioRetornaNul()
    {
        $this->assertNull($this->service->publicMapReservaVagasCotas([]));
    }

    function testMapReservaVagasCotasRetornaEstruturaCorreta()
    {
        $raw = [
            ['label' => 'Cota PCD', 'vagas' => 5, 'valorDestinado' => '1.000,00', 'naoAplicavel' => false],
            ['label' => 'Cota Negros', 'vagas' => 3, 'valorDestinado' => '500,00', 'naoAplicavel' => true],
        ];

        $result = $this->service->publicMapReservaVagasCotas($raw);

        $this->assertCount(2, $result);
        $this->assertSame('Cota PCD', $result[0]['label']);
        $this->assertSame(5, $result[0]['vagas']);
        $this->assertSame('1000.00', $result[0]['valor_destinado']);
        $this->assertFalse($result[0]['nao_aplicavel']);
        $this->assertSame('Cota Negros', $result[1]['label']);
        $this->assertTrue($result[1]['nao_aplicavel']);
        $this->assertSame('500.00', $result[1]['valor_destinado']);
    }

    // ======================= mapTiposFormasInscricao =======================

    function testMapTiposFormasInscricaoNullRetornaNul()
    {
        $this->assertNull($this->service->publicMapTiposFormasInscricao(null));
    }

    function testMapTiposFormasInscricaoPrevistasNaoRetornaNul()
    {
        $raw = ['previstasNoEdital' => 'nao', 'formas' => [['tipo' => 'online']]];
        $this->assertNull($this->service->publicMapTiposFormasInscricao($raw));
    }

    function testMapTiposFormasInscricaoFormasVaziasRetornaNul()
    {
        $raw = ['previstasNoEdital' => 'sim', 'formas' => []];
        $this->assertNull($this->service->publicMapTiposFormasInscricao($raw));
    }

    function testMapTiposFormasInscricaoRetornaFormas()
    {
        $raw = [
            'previstasNoEdital' => 'sim',
            'formas' => [
                ['tipo' => 'online', 'descricao' => 'Via site'],
                ['tipo' => 'presencial', 'descricao' => 'Na sede'],
            ],
        ];

        $result = $this->service->publicMapTiposFormasInscricao($raw);

        $this->assertCount(2, $result);
        $this->assertSame('online', $result[0]['tipo']);
        $this->assertSame('Via site', $result[0]['descricao']);
        $this->assertSame('presencial', $result[1]['tipo']);
    }

    function testMapTiposFormasInscricaoItemSemTipoEhIgnorado()
    {
        $raw = [
            'previstasNoEdital' => 'sim',
            'formas' => [
                ['tipo' => '', 'descricao' => 'Sem tipo'],
                ['tipo' => 'email', 'descricao' => 'Por e-mail'],
            ],
        ];

        $result = $this->service->publicMapTiposFormasInscricao($raw);

        $this->assertCount(1, $result);
        $this->assertSame('email', $result[0]['tipo']);
    }

    // ======================= mapTiposProponentes =======================

    function testMapTiposProponentesNullRetornaNul()
    {
        $this->assertNull($this->service->publicMapTiposProponentes(null));
    }

    function testMapTiposProponentesNaoArrayRetornaNul()
    {
        $this->assertNull($this->service->publicMapTiposProponentes('Pessoa Física'));
    }

    function testMapTiposProponentesConhecidosRetornaValoresApi()
    {
        $result = $this->service->publicMapTiposProponentes(['Pessoa Física', 'MEI', 'Coletivo', 'Pessoa Jurídica']);

        $this->assertSame([
            'pessoa_fisica',
            'mei_microempreendedor_individual',
            'coletivos_e_grupos_informais_sem_cnpj',
            'pessoa_juridica',
        ], $result);
    }

    function testMapTiposProponentesLabelDesconhecidaEhIgnorada()
    {
        $result = $this->service->publicMapTiposProponentes(['Pessoa Física', 'Desconhecido', 'MEI']);

        $this->assertSame(['pessoa_fisica', 'mei_microempreendedor_individual'], $result);
    }

    function testMapTiposProponentesTodosDesconhecidosRetornaNul()
    {
        $this->assertNull($this->service->publicMapTiposProponentes(['Tipo Inexistente']));
    }

    // ======================= mapOutrasModalidadesAcoesAfirmativas =======================

    function testMapOutrasModalidadesNullRetornaNul()
    {
        $this->assertNull($this->service->publicMapOutrasModalidadesAcoesAfirmativas(null));
    }

    function testMapOutrasModalidadesNaoArrayRetornaNul()
    {
        $this->assertNull($this->service->publicMapOutrasModalidadesAcoesAfirmativas('string'));
    }

    function testMapOutrasModalidadesRetornaEstrutura()
    {
        $raw = [
            'opcoes' => ['lei-rouanet', 'funcultural'],
            'outra_legislacao_descricao' => '  Descrição da lei  ',
            'bonus_agentes' => ['agente-1'],
            'bonus_tematicas' => [],
            'categoria_especifica' => ['cat-a'],
            'edital_especifico' => [],
        ];

        $result = $this->service->publicMapOutrasModalidadesAcoesAfirmativas($raw);

        $this->assertSame(['lei-rouanet', 'funcultural'], $result['opcoes']);
        $this->assertSame('Descrição da lei', $result['outra_legislacao_descricao']);
        $this->assertSame(['agente-1'], $result['bonus_agentes']);
        $this->assertSame([], $result['bonus_tematicas']);
        $this->assertSame(['cat-a'], $result['categoria_especifica']);
        $this->assertSame([], $result['edital_especifico']);
    }

    // ======================= getOpportunityStatus =======================

    function testGetOpportunityStatusNullRetornaNul()
    {
        $opp = (object) ['status' => null];
        $this->assertNull($this->service->publicGetOpportunityStatus($opp));
    }

    function testGetOpportunityStatusHabilitadoRetornaPayloadCorreto()
    {
        $opp = (object) ['status' => 1];
        $result = $this->service->publicGetOpportunityStatus($opp);

        $this->assertSame(1, $result['id']);
        $this->assertNotEmpty($result['label']);
    }

    function testGetOpportunityStatusRascunhoRetornaId()
    {
        $opp = (object) ['status' => 0];
        $result = $this->service->publicGetOpportunityStatus($opp);

        $this->assertSame(0, $result['id']);
    }

    function testGetOpportunityStatusDesconhecidoRetornaIdSemLabel()
    {
        $opp = (object) ['status' => 9999];
        $result = $this->service->publicGetOpportunityStatus($opp);

        $this->assertSame(9999, $result['id']);
        $this->assertNull($result['label']);
    }

    // ======================= mapMultiselectToString =======================

    function testMapMultiselectToStringNullRetornaNul()
    {
        $result = $this->service->publicMapMultiselectToString(MultiselectField::SEGMENTO, null);
        $this->assertNull($result);
    }

    function testMapMultiselectToStringArrayVazioRetornaNul()
    {
        $result = $this->service->publicMapMultiselectToString(MultiselectField::SEGMENTO, []);
        $this->assertNull($result);
    }

    function testMapMultiselectToStringChaveNaoAplicavelRetornaLabelSegmento()
    {
        $result = $this->service->publicMapMultiselectToString(
            MultiselectField::SEGMENTO,
            [SpecialOption::NOT_APPLICABLE->value]
        );

        $this->assertSame('Edital não se direciona a segmentos específicos', $result);
    }

    function testMapMultiselectToStringChaveNaoAplicavelRetornaLabelEtapa()
    {
        $result = $this->service->publicMapMultiselectToString(
            MultiselectField::ETAPA,
            [SpecialOption::NOT_APPLICABLE->value]
        );

        $this->assertSame('Edital não se direciona a etapa específica', $result);
    }

    function testMapMultiselectToStringChaveDesconhecidaRetornaChavePropria()
    {
        // Quando a chave não existe nas opções registradas, o fallback retorna a própria chave.
        $result = $this->service->publicMapMultiselectToString(
            MultiselectField::ETAPA,
            ['chave-que-nao-existe']
        );

        $this->assertSame('chave-que-nao-existe', $result);
    }

    function testMapMultiselectToStringMultiplasChavesRetornaStringConcatenada()
    {
        // Duas chaves desconhecidas → fallback retorna ambas separadas por vírgula
        $result = $this->service->publicMapMultiselectToString(
            MultiselectField::PAUTA,
            ['chave-a', 'chave-b']
        );

        $this->assertSame('chave-a, chave-b', $result);
    }

    // ======================= getEnteFederadoByOpportunity (DB-backed) =======================

    /**
     * Percorre o caminho completo do valor total: metadado gravado, releitura do banco e payload.
     * O helper isolado passar não garante isto — o valor ainda atravessa o getter e o mapeamento.
     */
    function testMapOpportunityToIntegrationPayloadPreservaValorTotalInteiro()
    {
        $this->assertSame('250.00', $this->payloadValorTotalPara('250'));
    }

    function testMapOpportunityToIntegrationPayloadPreservaValorTotalComUmaCasaDecimal()
    {
        $this->assertSame('32692.70', $this->payloadValorTotalPara('32692.7'));
    }

    function testMapOpportunityToIntegrationPayloadPreservaValorTotalComDuasCasasDecimais()
    {
        $this->assertSame('100698.85', $this->payloadValorTotalPara('100698.85'));
    }

    function testMapOpportunityToIntegrationPayloadSemValorTotalRetornaNul()
    {
        $opp = $this->createOpportunity();
        $payload = $this->service->mapOpportunityToIntegrationPayload(
            $this->service->findOpportunityWithIntegrationData($opp->id)
        );

        $this->assertNull($payload['valor_total_edital']);
    }

    function testMapOpportunityToIntegrationPayloadValorTotalVazioRetornaNul()
    {
        $this->assertNull($this->payloadValorTotalPara(''));
    }

    function testMapOpportunityToIntegrationPayloadValorTotalEmFormatoBrasileiro()
    {
        $this->assertSame('100698.85', $this->payloadValorTotalPara('100.698,85'));
    }

    /**
     * O valor total precisa continuar batendo com a soma das categorias depois de normalizado.
     * É a relação que denuncia adulteração do valor: as categorias vão cruas para o payload.
     */
    function testMapOpportunityToIntegrationPayloadValorTotalBateComSomaDasCategorias()
    {
        $opp = $this->createOpportunity();
        $oppId = $opp->id;

        $this->app->disableAccessControl();
        $opp->registrationRanges = [
            ['label' => 'Categoria A', 'limit' => 5, 'value' => 81817.82],
            ['label' => 'Categoria B', 'limit' => 1, 'value' => 18881.03],
        ];
        $opp->setMetadata('totalResource', '100698.85');
        $opp->save(true);
        $this->app->enableAccessControl();

        $this->app->em->detach($opp);
        $payload = $this->service->mapOpportunityToIntegrationPayload(
            $this->service->findOpportunityWithIntegrationData($oppId)
        );

        $soma = array_sum(array_column($payload['categorias_edital'], 'value'));

        $this->assertSame('100698.85', $payload['valor_total_edital']);
        $this->assertEqualsWithDelta($soma, (float) $payload['valor_total_edital'], 0.001);
    }

    /** Grava totalResource numa oportunidade nova e devolve o valor_total_edital do payload. */
    private function payloadValorTotalPara(string $totalResource): ?string
    {
        $opp = $this->createOpportunity();
        $oppId = $opp->id;

        $this->app->disableAccessControl();
        $opp->setMetadata('totalResource', $totalResource);
        $opp->save(true);
        $this->app->enableAccessControl();

        $this->app->em->detach($opp);

        return $this->service->mapOpportunityToIntegrationPayload(
            $this->service->findOpportunityWithIntegrationData($oppId)
        )['valor_total_edital'];
    }

    private function createOpportunity(): Opportunity
    {
        $user = $this->userDirector->createUser();
        $this->login($user);
        $this->app->disableAccessControl();
        $className = $user->profile->opportunityClassName;
        $opp = new $className();
        $opp->owner = $user->profile;
        $opp->ownerEntity = $user->profile;
        $opp->name = 'Opp Ente Test';
        $opp->shortDescription = 'desc';
        $opp->status = Opportunity::STATUS_DRAFT;
        $opp->save(true);
        $this->app->enableAccessControl();
        return $opp;
    }

    private function persistFederativeEntity(string $document, string $name): FederativeEntity
    {
        $ente = new FederativeEntity();
        $ente->name = $name;
        $ente->document = $document;
        $ente->exercices = [];
        $ente->createTimestamp = new \DateTime();
        $this->app->em->persist($ente);
        $this->app->em->flush();
        return $ente;
    }

    function testGetEnteFederadoSemFederativeEntityIdRetornaNul()
    {
        $opp = $this->createOpportunity();

        $this->assertNull($this->service->publicGetEnteFederadoByOpportunity($opp));
    }

    function testGetEnteFederadoComEntidadeValidaRetornaNomeEDocumento()
    {
        $opp = $this->createOpportunity();
        $ente = $this->persistFederativeEntity('12345678000195', 'Secretaria de Cultura de SP');

        $this->app->disableAccessControl();
        $opp->setMetadata('federativeEntityId', (string) $ente->id);
        $opp->save(true);
        $this->app->enableAccessControl();

        $result = $this->service->publicGetEnteFederadoByOpportunity($opp);

        $this->assertNotNull($result);
        $this->assertSame('Secretaria de Cultura de SP', $result['name']);
        $this->assertSame('12345678000195', $result['document']);
    }

    function testGetEnteFederadoComDocumentoApenasEspacosRetornaNul()
    {
        $opp = $this->createOpportunity();
        // Documento só com espaços → normalizeString → null → retorna null
        $ente = $this->persistFederativeEntity('   ', 'Ente sem doc');

        $this->app->disableAccessControl();
        $opp->setMetadata('federativeEntityId', (string) $ente->id);
        $opp->save(true);
        $this->app->enableAccessControl();

        $this->assertNull($this->service->publicGetEnteFederadoByOpportunity($opp));
    }

    function testGetEnteFederadoComIdInexistenteRetornaNul()
    {
        $opp = $this->createOpportunity();

        $this->app->disableAccessControl();
        $opp->setMetadata('federativeEntityId', '999999999');
        $opp->save(true);
        $this->app->enableAccessControl();

        $this->assertNull($this->service->publicGetEnteFederadoByOpportunity($opp));
    }

    // ======================= mapOpportunityToIntegrationPayload (integração) =======================

    /**
     * Verifica que o payload retorna todas as 30 chaves esperadas pela API,
     * mesmo para uma oportunidade sem campos opcionais configurados (tudo null).
     */
    function testMapOpportunityToIntegrationPayloadRetornaTodasChavesEsperadas()
    {
        $opp = $this->createOpportunity();
        $loaded = $this->service->findOpportunityWithIntegrationData($opp->id);
        $this->assertNotNull($loaded, 'findOpportunityWithIntegrationData deve encontrar a oportunidade');

        $payload = $this->service->mapOpportunityToIntegrationPayload($loaded);

        $expectedKeys = [
            'id', 'numero_e_titulo_edital', 'forma_de_execucao', 'status',
            'data_publicacao_edital', 'detalhamento_objeto', 'numero_previsto_vagas',
            'valor_total_edital', 'data_inicial_prazo_inscricao', 'data_final_prazo_inscricao',
            'tipos_proponentes', 'segmentos_artistico_culturais', 'segmento_artistico_cultural_especificar',
            'etapas_fazer_cultural', 'etapa_fazer_cultural_especificar', 'pautas_especificas',
            'pauta_especifica_especificar', 'categorias_edital', 'recursos_territorios_prioritarios',
            'links_da_pagina_pnab', 'pdf_edital', 'recursos_outras_fontes', 'tipos_formas_inscricao',
            'reserva_vagas_cotas', 'outras_modalidades_acoes_afirmativas', 'ente_federado',
            'id_exercicio', 'id_meta', 'id_acao', 'id_atividade',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $payload, "Payload deve conter a chave '{$key}'");
        }

        $this->assertSame($opp->id, $payload['id']);
        $this->assertSame('Opp Ente Test', $payload['numero_e_titulo_edital']);
        $this->assertNull($payload['ente_federado'], 'Sem ente federado configurado deve ser null');
        $this->assertNull($payload['id_exercicio']);
        $this->assertNull($payload['id_meta']);
        $this->assertNull($payload['id_acao']);
        $this->assertNull($payload['id_atividade']);
    }

    /**
     * Verifica a lógica de conversão dos campos PAR (id_exercicio, id_meta, id_acao, id_atividade):
     * valores numéricos são convertidos para int; string vazia e ausência são null.
     * O caso '0' é relevante: é um int válido (zero), não deve ser tratado como ausente.
     */
    function testMapOpportunityToIntegrationPayloadConverteCamposParIds()
    {
        $opp = $this->createOpportunity();
        $oppId = $opp->id;

        $this->app->disableAccessControl();
        $opp->setMetadata('parExercicioId', '10');
        $opp->setMetadata('parAtividadeId', '0');
        // parMetaId e parAcaoId não configurados → devem ser null no payload
        $opp->save(true);
        $this->app->enableAccessControl();

        // Detach para garantir que findOpportunityWithIntegrationData recarrega do banco
        $this->app->em->detach($opp);
        $loaded = $this->service->findOpportunityWithIntegrationData($oppId);

        $payload = $this->service->mapOpportunityToIntegrationPayload($loaded);

        $this->assertSame(10, $payload['id_exercicio'], "'10' deve ser convertido para int 10");
        $this->assertSame(0, $payload['id_atividade'], "'0' deve ser convertido para int 0, não null");
        $this->assertNull($payload['id_meta'], 'Não configurado deve ser null');
        $this->assertNull($payload['id_acao'], 'Não configurado deve ser null');
    }
}
