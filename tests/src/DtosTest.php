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
