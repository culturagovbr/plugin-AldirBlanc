<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Dtos\GestorDocument;
use AldirBlanc\Dtos\Opportunity as OpportunityDto;
use AldirBlanc\Dtos\OpportunityId;
use AldirBlanc\Dtos\ParAction;
use Tests\Abstract\TestCase;

/**
 * Testes de contrato dos DTOs simples do plugin: construção e round-trip de array.
 */
class DtosTest extends TestCase
{
    function testGestorDocumentArmazenaODocumento()
    {
        $dto = new GestorDocument('12345678901');

        $this->assertSame('12345678901', $dto->document);
    }

    /**
     * Caso real: UserService::getCpf() retorna '' quando o agente não tem CPF cadastrado
     * (preg_replace que remove não-dígitos de uma string vazia/sem dígitos resulta em '').
     * GestorDocument não valida nada — aceita string vazia sem reclamar.
     */
    function testGestorDocumentAceitaStringVazia()
    {
        $dto = new GestorDocument('');

        $this->assertSame('', $dto->document);
    }

    function testGestorDocumentNaoAceitaNull()
    {
        $this->expectException(\TypeError::class);

        new GestorDocument(null);
    }

    /**
     * GestorDocument não valida formato/tamanho/dígito verificador de CPF — é só um
     * envelope de string. Quem normalmente alimenta esse DTO (UserService::getCpf()) já
     * remove caracteres não numéricos antes, mas o DTO em si aceitaria qualquer coisa
     * se construído diretamente com um valor "cru".
     */
    function testGestorDocumentAceitaCpfComFormatacaoPontuacao()
    {
        $dto = new GestorDocument('123.456.789-01');

        $this->assertSame('123.456.789-01', $dto->document);
    }

    function testGestorDocumentAceitaCpfComTamanhoMenorQueOEsperado()
    {
        $dto = new GestorDocument('123');

        $this->assertSame('123', $dto->document);
    }

    function testGestorDocumentAceitaCpfComTamanhoMaiorQueOEsperado()
    {
        $dto = new GestorDocument('123456789012345678');

        $this->assertSame('123456789012345678', $dto->document);
    }

    function testGestorDocumentAceitaCpfComLetrasMisturadas()
    {
        $dto = new GestorDocument('abc12345678');

        $this->assertSame('abc12345678', $dto->document);
    }

    /**
     * "11111111111" é um CPF formalmente bem-formado (11 dígitos) mas matematicamente
     * inválido (dígito verificador sempre falha para sequências repetidas) — o DTO não
     * tem como saber disso, pois não faz nenhuma validação de dígito verificador.
     */
    function testGestorDocumentAceitaCpfComDigitosRepetidos()
    {
        $dto = new GestorDocument('11111111111');

        $this->assertSame('11111111111', $dto->document);
    }

    function testGestorDocumentAceitaCpfComEspacos()
    {
        $dto = new GestorDocument('  123 456 789 01  ');

        $this->assertSame('  123 456 789 01  ', $dto->document);
    }

    /**
     * Achado de segurança: o documento é injetado puro na URL via str_replace()
     * (AbstractClient::prepareEndpoint), sem urlencode/rawurlencode. Um valor com
     * caracteres especiais de URL passa pelo DTO sem qualquer aviso ou rejeição —
     * a sanitização (se existir) precisaria acontecer em outra camada, que hoje não existe.
     */
    function testGestorDocumentAceitaCaracteresQuePoderiamQuebrarUrl()
    {
        $dto = new GestorDocument('123/../outro-endpoint?x=1&y=2');

        $this->assertSame('123/../outro-endpoint?x=1&y=2', $dto->document);
    }

    function testOpportunityIdArmazenaOId()
    {
        $dto = new OpportunityId(42);

        $this->assertSame(42, $dto->id);
    }

    /**
     * OpportunityId não valida o valor — zero/negativo são aceitos sem erro (não há
     * verificação de "id válido" no DTO, isso fica a cargo de quem o consome).
     */
    function testOpportunityIdAceitaZeroENegativoSemValidar()
    {
        $this->assertSame(0, (new OpportunityId(0))->id);
        $this->assertSame(-1, (new OpportunityId(-1))->id);
    }

    function testOpportunityIdNaoAceitaStringNaoNumerica()
    {
        $this->expectException(\TypeError::class);

        new OpportunityId('abc');
    }

    /**
     * Sem strict_types, PHP faz coerção de string numérica para int automaticamente.
     */
    function testOpportunityIdAceitaStringNumericaCoercida()
    {
        $dto = new OpportunityId('42');

        $this->assertSame(42, $dto->id);
    }

    function testOpportunityDtoPreservaValorTotalEditalNoRoundTrip()
    {
        $dto = OpportunityDto::fromArray(['id' => 1, 'valor_total_edital' => '100698.85']);

        $this->assertSame('100698.85', $dto->valor_total_edital);
        $this->assertSame('100698.85', $dto->toArray()['valor_total_edital']);
    }

    function testOpportunityDtoPreservaOZeroFinalDoValorTotalEdital()
    {
        $dto = OpportunityDto::fromArray(['id' => 1, 'valor_total_edital' => '32692.70']);

        $this->assertSame('32692.70', $dto->toArray()['valor_total_edital']);
    }

    function testOpportunityDtoPreservaCentavoIsoladoNoValorTotalEdital()
    {
        $dto = OpportunityDto::fromArray(['id' => 1, 'valor_total_edital' => '0.01']);

        $this->assertSame('0.01', $dto->toArray()['valor_total_edital']);
    }

    function testOpportunityDtoSemValorTotalEditalResultaEmNull()
    {
        $dto = OpportunityDto::fromArray(['id' => 1]);

        $this->assertNull($dto->toArray()['valor_total_edital']);
    }

    function testOpportunityDtoComValorTotalEditalNullResultaEmNull()
    {
        $dto = OpportunityDto::fromArray(['id' => 1, 'valor_total_edital' => null]);

        $this->assertNull($dto->toArray()['valor_total_edital']);
    }

    /** O DTO só repassa: quem garante as duas casas é o mapeamento, e float aqui já chega tarde demais. */
    function testOpportunityDtoRecebendoFloatPerdeAFormatacaoDeDuasCasas()
    {
        $dto = OpportunityDto::fromArray(['id' => 1, 'valor_total_edital' => 32692.70]);

        $this->assertSame('32692.7', $dto->toArray()['valor_total_edital']);
    }

    function testOpportunityDtoPreservaCategoriasEditalNoRoundTrip()
    {
        $categorias = [['label' => 'Categoria A', 'limit' => 5, 'value' => 81817.82]];

        $dto = OpportunityDto::fromArray(['id' => 1, 'categorias_edital' => $categorias]);

        $this->assertSame($categorias, $dto->toArray()['categorias_edital']);
    }

    function testOpportunityDtoPreservaValorDestinadoDasCotasNoRoundTrip()
    {
        $cotas = [
            ['label' => 'Pessoas negras', 'vagas' => 2, 'valor_destinado' => '32727.12', 'nao_aplicavel' => false],
            ['label' => 'Pessoas indígenas', 'vagas' => 1, 'valor_destinado' => '16363.50', 'nao_aplicavel' => false],
        ];

        $dto = OpportunityDto::fromArray(['id' => 1, 'reserva_vagas_cotas' => $cotas]);

        $this->assertSame($cotas, $dto->toArray()['reserva_vagas_cotas']);
    }

    function testOpportunityDtoComReservaVagasCotasNaoArrayResultaEmNull()
    {
        $dto = OpportunityDto::fromArray(['id' => 1, 'reserva_vagas_cotas' => 'nao e array']);

        $this->assertNull($dto->toArray()['reserva_vagas_cotas']);
    }

    function testOpportunityDtoPreservaRecursosOutrasFontesNoRoundTrip()
    {
        $recursos = [
            'houve_utilizacao' => 'sim',
            'recursos_proprios' => '47011.05',
            'convenios_parcerias' => '11573.28',
            'emendas_parlamentares' => '9992.87',
            'remanescentes_ciclo_1' => '24611.60',
            'outras_fontes' => [['nome_fonte' => 'Fonte A', 'valor' => '1862.19']],
        ];

        $dto = OpportunityDto::fromArray(['id' => 1, 'recursos_outras_fontes' => $recursos]);

        $this->assertSame($recursos, $dto->toArray()['recursos_outras_fontes']);
    }

    function testOpportunityDtoPreservaOZeroFinalDosValoresMonetariosAninhados()
    {
        $dto = OpportunityDto::fromArray(['id' => 1, 'reserva_vagas_cotas' => [
            ['label' => 'Cota', 'vagas' => 1, 'valor_destinado' => '16363.50', 'nao_aplicavel' => false],
        ]]);

        $this->assertSame('16363.50', $dto->toArray()['reserva_vagas_cotas'][0]['valor_destinado']);
    }

    /** Payload com os 30 campos preenchidos, para exercitar o round-trip inteiro. */
    private function payloadCompletoDeOportunidade(): array
    {
        return [
            'id' => 9339,
            'numero_e_titulo_edital' => 'EDITAL nº II/2026',
            'forma_de_execucao' => 'Execução cultural',
            'status' => ['id' => 1, 'label' => 'Ativado'],
            'data_publicacao_edital' => '2026-08-02 15:29:22',
            'detalhamento_objeto' => 'Está dividido em duas categorias',
            'numero_previsto_vagas' => 6,
            'valor_total_edital' => '100698.85',
            'data_inicial_prazo_inscricao' => '2026-08-03 08:00:00',
            'data_final_prazo_inscricao' => '2026-08-14 18:00:00',
            'tipos_proponentes' => ['pessoa_fisica', 'pessoa_juridica'],
            'segmentos_artistico_culturais' => 'Artes Visuais, Circo',
            'segmento_artistico_cultural_especificar' => 'Outro segmento',
            'etapas_fazer_cultural' => 'Criação, Difusão',
            'etapa_fazer_cultural_especificar' => 'Outra etapa',
            'pautas_especificas' => 'Edital não se direciona a pautas específicas',
            'pauta_especifica_especificar' => 'Outra pauta',
            'categorias_edital' => [['label' => 'Categoria A', 'limit' => 5, 'value' => 81817.82]],
            'recursos_territorios_prioritarios' => 'Edital não se direciona a territórios',
            'links_da_pagina_pnab' => [['url' => 'https://exemplo.gov.br', 'label' => 'Site']],
            'pdf_edital' => 'https://exemplo.gov.br/edital.pdf',
            'recursos_outras_fontes' => ['houve_utilizacao' => 'nao', 'recursos_proprios' => null],
            'tipos_formas_inscricao' => [['tipo' => 'online', 'descricao' => 'Formulário']],
            'reserva_vagas_cotas' => [['label' => 'Cota', 'vagas' => 2, 'valor_destinado' => '32727.12', 'nao_aplicavel' => false]],
            'outras_modalidades_acoes_afirmativas' => ['opcoes' => ['nao_previstas']],
            'ente_federado' => ['name' => 'MUNICIPIO DE EXEMPLO', 'document' => '01611339000197'],
            'id_exercicio' => 4322,
            'id_meta' => 11963,
            'id_acao' => 10817,
            'id_atividade' => 17552,
        ];
    }

    function testOpportunityDtoRoundTripPreservaOPayloadCompleto()
    {
        $payload = $this->payloadCompletoDeOportunidade();

        $this->assertSame($payload, OpportunityDto::fromArray($payload)->toArray());
    }

    function testOpportunityDtoComPayloadMinimoAnulaTodosOsCamposOpcionais()
    {
        $resultado = OpportunityDto::fromArray(['id' => 9339])->toArray();

        $this->assertSame(9339, $resultado['id']);
        foreach (array_diff(array_keys($resultado), ['id']) as $campo) {
            $this->assertNull($resultado[$campo], "campo {$campo} deveria ser null");
        }
    }

    function testOpportunityDtoConverteParaStringOsCamposDeTexto()
    {
        $campos = [
            'numero_e_titulo_edital', 'forma_de_execucao', 'data_publicacao_edital',
            'detalhamento_objeto', 'valor_total_edital', 'data_inicial_prazo_inscricao',
            'data_final_prazo_inscricao', 'segmento_artistico_cultural_especificar',
            'etapa_fazer_cultural_especificar', 'pauta_especifica_especificar', 'pdf_edital',
        ];

        $resultado = OpportunityDto::fromArray(['id' => 1] + array_fill_keys($campos, 123))->toArray();

        foreach ($campos as $campo) {
            $this->assertSame('123', $resultado[$campo], "campo {$campo}");
        }
    }

    function testOpportunityDtoAnulaOsCamposDeTextoQuandoValorEhNull()
    {
        $campos = [
            'numero_e_titulo_edital', 'forma_de_execucao', 'data_publicacao_edital',
            'detalhamento_objeto', 'valor_total_edital', 'data_inicial_prazo_inscricao',
            'data_final_prazo_inscricao', 'segmento_artistico_cultural_especificar',
            'etapa_fazer_cultural_especificar', 'pauta_especifica_especificar', 'pdf_edital',
        ];

        $resultado = OpportunityDto::fromArray(['id' => 1] + array_fill_keys($campos, null))->toArray();

        foreach ($campos as $campo) {
            $this->assertNull($resultado[$campo], "campo {$campo}");
        }
    }

    function testOpportunityDtoConverteParaIntOsCamposNumericos()
    {
        $campos = ['numero_previsto_vagas', 'id_exercicio', 'id_meta', 'id_acao', 'id_atividade'];

        $resultado = OpportunityDto::fromArray(['id' => 1] + array_fill_keys($campos, '42'))->toArray();

        foreach ($campos as $campo) {
            $this->assertSame(42, $resultado[$campo], "campo {$campo}");
        }
    }

    function testOpportunityDtoPreservaOZeroNosCamposNumericos()
    {
        $campos = ['numero_previsto_vagas', 'id_exercicio', 'id_meta', 'id_acao', 'id_atividade'];

        $resultado = OpportunityDto::fromArray(['id' => 1] + array_fill_keys($campos, '0'))->toArray();

        foreach ($campos as $campo) {
            $this->assertSame(0, $resultado[$campo], "campo {$campo} deveria ser zero, não null");
        }
    }

    function testOpportunityDtoAnulaOsCamposDeListaQuandoNaoSaoArray()
    {
        $campos = ['status', 'links_da_pagina_pnab', 'reserva_vagas_cotas',
                   'outras_modalidades_acoes_afirmativas', 'ente_federado'];

        $resultado = OpportunityDto::fromArray(['id' => 1] + array_fill_keys($campos, 'texto'))->toArray();

        foreach ($campos as $campo) {
            $this->assertNull($resultado[$campo], "campo {$campo}");
        }
    }

    function testOpportunityDtoRepassaSemTratamentoOsCamposLivres()
    {
        $campos = [
            'tipos_proponentes' => ['pessoa_fisica'],
            'segmentos_artistico_culturais' => 'Circo',
            'etapas_fazer_cultural' => 'Criação',
            'pautas_especificas' => 'Sem pauta',
            'categorias_edital' => [['value' => 81817.82]],
            'recursos_territorios_prioritarios' => 'Sem território',
            'recursos_outras_fontes' => ['houve_utilizacao' => 'nao'],
            'tipos_formas_inscricao' => [['tipo' => 'online']],
        ];

        $resultado = OpportunityDto::fromArray(['id' => 1] + $campos)->toArray();

        foreach ($campos as $campo => $valor) {
            $this->assertSame($valor, $resultado[$campo], "campo {$campo}");
        }
    }

    function testOpportunityDtoNormalizaEnteFederadoComCamposAusentes()
    {
        $resultado = OpportunityDto::fromArray(['id' => 1, 'ente_federado' => []])->toArray();

        $this->assertSame(['name' => '', 'document' => ''], $resultado['ente_federado']);
    }

    function testOpportunityDtoConverteParaStringONomeEODocumentoDoEnteFederado()
    {
        $resultado = OpportunityDto::fromArray([
            'id' => 1,
            'ente_federado' => ['name' => 123, 'document' => 456],
        ])->toArray();

        $this->assertSame(['name' => '123', 'document' => '456'], $resultado['ente_federado']);
    }

    function testOpportunityDtoSemIdResultaEmZero()
    {
        $this->assertSame(0, OpportunityDto::fromArray([])->toArray()['id']);
    }

    function testOpportunityDtoConverteIdParaInt()
    {
        $this->assertSame(9339, OpportunityDto::fromArray(['id' => '9339'])->toArray()['id']);
    }

    /** Sem o cast explícito, texto não numérico num campo tipado `?int` derrubaria a construção. */
    function testOpportunityDtoZeraOsCamposNumericosComTextoNaoNumerico()
    {
        $campos = ['numero_previsto_vagas', 'id_exercicio', 'id_meta', 'id_acao', 'id_atividade'];

        $resultado = OpportunityDto::fromArray(['id' => 1] + array_fill_keys($campos, 'abc'))->toArray();

        foreach ($campos as $campo) {
            $this->assertSame(0, $resultado[$campo], "campo {$campo}");
        }
    }

    function testOpportunityDtoTruncaOsCamposNumericosComValorFracionario()
    {
        $resultado = OpportunityDto::fromArray(['id' => 1, 'numero_previsto_vagas' => 6.9])->toArray();

        $this->assertSame(6, $resultado['numero_previsto_vagas']);
    }

    function testOpportunityDtoSemIdNaoGeraAvisoEResultaEmZero()
    {
        $id = $this->semAvisoDoPhp(fn() => OpportunityDto::fromArray([])->toArray()['id']);

        $this->assertSame(0, $id);
    }

    function testOpportunityDtoEnteFederadoSemNomeNemDocumentoNaoGeraAviso()
    {
        $ente = $this->semAvisoDoPhp(
            fn() => OpportunityDto::fromArray(['id' => 1, 'ente_federado' => []])->toArray()['ente_federado']
        );

        $this->assertSame(['name' => '', 'document' => ''], $ente);
    }

    function testOpportunityDtoComListasVaziasPreservaOArrayVazio()
    {
        $campos = ['status', 'links_da_pagina_pnab', 'reserva_vagas_cotas', 'outras_modalidades_acoes_afirmativas'];

        $resultado = OpportunityDto::fromArray(['id' => 1] + array_fill_keys($campos, []))->toArray();

        foreach ($campos as $campo) {
            $this->assertSame([], $resultado[$campo], "campo {$campo}");
        }
    }

    function testOpportunityDtoAnulaOsCamposLivresQuandoValorEhNull()
    {
        $campos = ['tipos_proponentes', 'segmentos_artistico_culturais', 'etapas_fazer_cultural',
                   'pautas_especificas', 'categorias_edital', 'recursos_territorios_prioritarios',
                   'recursos_outras_fontes', 'tipos_formas_inscricao'];

        $resultado = OpportunityDto::fromArray(['id' => 1] + array_fill_keys($campos, null))->toArray();

        foreach ($campos as $campo) {
            $this->assertNull($resultado[$campo], "campo {$campo}");
        }
    }

    /** Executa a chamada falhando o teste se o PHP emitir qualquer aviso pelo caminho. */
    private function semAvisoDoPhp(callable $chamada): mixed
    {
        $avisos = [];
        set_error_handler(function (int $nivel, string $mensagem) use (&$avisos) {
            $avisos[] = $mensagem;
            return true;
        });

        try {
            $resultado = $chamada();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $avisos, 'a construção do DTO não deve depender de chave ausente');

        return $resultado;
    }

    function testParActionFromArrayRoundTripPreservaValueLabelRaw()
    {
        $data = ['nome_acao' => '1.1 Fomento Cultural', 'id_par_acao_meta_acao' => 1, 'valor_acao' => '100000.00'];

        $action = ParAction::fromArray($data);

        $this->assertSame('1.1 Fomento Cultural', $action->value);
        $this->assertSame('1.1 Fomento Cultural', $action->label);
        $this->assertSame($data, $action->raw);
        $this->assertSame([
            'value' => '1.1 Fomento Cultural',
            'label' => '1.1 Fomento Cultural',
            'raw' => $data,
        ], $action->toArray());
    }

    function testParActionFromArraySemNomeAcaoResultaEmLabelVazio()
    {
        $action = ParAction::fromArray(['id_par_acao_meta_acao' => 1]);

        $this->assertSame('', $action->label);
        $this->assertSame('', $action->value);
    }

    function testParActionFromArrayComNomeAcaoNullResultaEmLabelVazio()
    {
        $action = ParAction::fromArray(['nome_acao' => null]);

        $this->assertSame('', $action->label);
    }

    function testParActionFromArrayComNomeAcaoNumericoConverteParaString()
    {
        $action = ParAction::fromArray(['nome_acao' => 123]);

        $this->assertSame('123', $action->label);
    }

    /**
     * value e label são sempre idênticos quando construídos via fromArray — não é um descuido,
     * é o contrato atual do DTO (não há nenhum lugar no código que diferencie os dois depois).
     */
    function testParActionFromArrayValueEhSempreIgualALabel()
    {
        $action = ParAction::fromArray(['nome_acao' => 'Qualquer Ação']);

        $this->assertSame($action->value, $action->label);
    }

    function testParActionFromArrayComArrayVazioResultaEmLabelVazioERawVazio()
    {
        $action = ParAction::fromArray([]);

        $this->assertSame('', $action->label);
        $this->assertSame([], $action->raw);
    }

    function testParActionFromArrayComNomeAcaoBooleanoConverteParaString()
    {
        $this->assertSame('1', ParAction::fromArray(['nome_acao' => true])->label);
        $this->assertSame('', ParAction::fromArray(['nome_acao' => false])->label);
    }

    function testParActionFromArrayComNomeAcaoFloatConverteParaString()
    {
        $this->assertSame('1.5', ParAction::fromArray(['nome_acao' => 1.5])->label);
    }

    /**
     * Achado: fromArray() não dá trim em nome_acao — um label só com espaços passa direto,
     * sem normalização (quem normaliza, com trim, é o Controller ao deduplicar/exibir).
     */
    function testParActionFromArrayComNomeAcaoSoEspacosNaoEhNormalizado()
    {
        $action = ParAction::fromArray(['nome_acao' => '   ']);

        $this->assertSame('   ', $action->label);
    }

    function testParActionFromArrayPreservaChavesExtrasNoRaw()
    {
        $data = ['nome_acao' => 'Ação X', 'id_par_cadastro' => 99, 'excluido' => false];

        $action = ParAction::fromArray($data);

        $this->assertSame($data, $action->raw);
        $this->assertArrayHasKey('id_par_cadastro', $action->raw);
    }

    function testParActionToArrayChamadoDuasVezesRetornaResultadoConsistente()
    {
        $action = ParAction::fromArray(['nome_acao' => 'Ação Y']);

        $this->assertSame($action->toArray(), $action->toArray());
    }
}
