<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Controller;
use AldirBlanc\Services\ResultadoDeParaService;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\RegistrationFieldConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\LoadsConectaResponses;
use Tests\AldirBlanc\Traits\SendsOpportunityThroughJob;
use Tests\Traits\UserDirector;

/** De/para por edital: campos da ficha, fontes das colunas do modelo, validação e persistência. */
class ResultadoDeParaServiceTest extends TestCase
{
    use UserDirector;
    use LoadsConectaResponses;
    use SendsOpportunityThroughJob;

    private function novoEdital(): Opportunity
    {
        return $this->createOpportunity($this->userDirector->createUser());
    }

    private function addCampo(Opportunity $opp, string $title, string $type, int $order = 1, array $config = []): int
    {
        $this->app->disableAccessControl();
        $campo = new RegistrationFieldConfiguration();
        $campo->owner = $opp;
        $campo->title = $title;
        $campo->fieldType = $type;
        $campo->displayOrder = $order;
        $campo->config = $config;
        $campo->save(true);
        $this->app->enableAccessControl();

        return (int) $campo->id;
    }

    private function service(): ResultadoDeParaService
    {
        return new ResultadoDeParaService();
    }

    function testCamposDaFichaListaOsCamposIgnorandoSecao()
    {
        $edital = $this->novoEdital();
        $this->addCampo($edital, 'CEP do local de execução', 'text', 1);
        $this->addCampo($edital, 'Dados da execução', 'section', 2);
        $this->addCampo($edital, 'Data de início da execução', 'date', 3);

        $campos = $this->service()->camposDaFicha($edital);
        $titulos = array_column($campos, 'title');

        $this->assertContains('CEP do local de execução', $titulos);
        $this->assertContains('Data de início da execução', $titulos);
        $this->assertNotContains('Dados da execução', $titulos, 'seção é cabeçalho, não entra como campo');
        $this->assertSame('date', $campos[array_search('Data de início da execução', $titulos, true)]['fieldType']);
    }

    private function planilha(string $resposta): array
    {
        $modelo = $this->respostaDaConecta($resposta);

        return ['modelo' => $modelo['modelo'], 'colunas' => $modelo['campos']];
    }

    private function salvaFontes(Opportunity $edital, array $planilha, array $fontes): array
    {
        return $this->service()->salvar($edital, $planilha, $fontes)['fontes'];
    }

    public function testEditalComecaSemFonteMesmoComOFormatoAntigoGravado(): void
    {
        $edital = $this->novoEdital();
        $cep = $this->addCampo($edital, 'CEP', 'text', 1);
        $conn = $this->app->em->getConnection();
        $antigo = json_encode(['cep_local_execucao' => $cep]);
        foreach (['cultBrResultadoDePara', 'cultBrResultadoDocumentoPorTipo'] as $chave) {
            $conn->executeStatement('INSERT INTO opportunity_meta (object_id, key, value) VALUES (?, ?, ?)', [(int) $edital->id, $chave, $antigo]);
        }

        $this->assertSame([], $this->service()->fontes((int) $edital->id, 'pontos_de_cultura'));

        $this->salvaFontes($edital, $this->planilha('modelo-pontos-de-cultura'), ['cep_espaco_cultural' => "campo:{$cep}"]);

        $this->assertSame(
            [$antigo, $antigo],
            $conn->fetchFirstColumn("SELECT value FROM opportunity_meta WHERE object_id = ? AND key IN ('cultBrResultadoDePara', 'cultBrResultadoDocumentoPorTipo')", [(int) $edital->id]),
            'o de/para antigo segue no banco como estava',
        );
    }

    #[DataProvider('modelos')]
    public function testGuardaAFonteDeCadaColunaDoModelo(string $resposta, string $colunaDeValor): void
    {
        $edital = $this->novoEdital();
        $raca = $this->addCampo($edital, 'Raça', 'select', 1);
        $cpf = $this->addCampo($edital, 'CPF', 'cpf', 2);
        $planilha = $this->planilha($resposta);

        $gravadas = $this->salvaFontes($edital, $planilha, [
            'raca_etnia' => "campo:{$raca}",
            'tipo_agente' => 'inscricao:tipo',
            'cota' => 'inscricao:faixa',
            $colunaDeValor => 'inscricao:valor',
            'cpf' => ['Pessoa Física' => "campo:{$cpf}", 'MEI' => "campo:{$cpf}"],
        ]);

        $this->assertSame([
            'tipo_agente' => 'inscricao:tipo',
            'cpf' => ['Pessoa Física' => "campo:{$cpf}", 'MEI' => "campo:{$cpf}"],
            'cota' => 'inscricao:faixa',
            'raca_etnia' => "campo:{$raca}",
            $colunaDeValor => 'inscricao:valor',
        ], $gravadas);
        $this->assertSame($gravadas, $this->service()->fontes((int) $edital->id, $planilha['modelo']));
    }

    public static function modelos(): array
    {
        return ['2.1' => ['modelo-pontos-de-cultura', 'valor_total_subsidio'], '2.3' => ['modelo-premio-cultura-viva', 'valor_premio']];
    }

    #[DataProvider('fontesRecusadas')]
    public function testFonteQueNaoServeNaoEGravada(string $coluna, mixed $fonte): void
    {
        $edital = $this->novoEdital();
        $alheio = $this->addCampo($this->novoEdital(), 'Campo de outro edital', 'text', 1);

        $recebidas = json_decode(str_replace('ALHEIO', (string) $alheio, json_encode([$coluna => $fonte])), true);

        $this->assertSame([], $this->salvaFontes($edital, $this->planilha('modelo-pontos-de-cultura'), $recebidas));
    }

    public static function fontesRecusadas(): array
    {
        return [
            'coluna fora do modelo' => ['coluna_inventada', 'inscricao:tipo'],
            'campo de outro edital' => ['raca_etnia', 'campo:ALHEIO'],
            'fonte inventada' => ['raca_etnia', 'inscricao:endereco'],
            'fonte que não é texto' => ['raca_etnia', 12],
            'lista em coluna de fonte única' => ['raca_etnia', ['Pessoa Física' => 'inscricao:tipo']],
            'texto em coluna por tipo' => ['cpf', 'inscricao:tipo'],
            'dado da inscrição como documento' => ['cpf', ['Pessoa Física' => 'inscricao:tipo']],
            'tipo de proponente inventado' => ['cnpj', ['Cooperativa' => 'campo:ALHEIO']],
            'sem fonte' => ['raca_etnia', null],
        ];
    }

    public function testDocumentoPorTipoGuardaSoOsTiposComCampoDoEdital(): void
    {
        $edital = $this->novoEdital();
        $cnpj = $this->addCampo($edital, 'CNPJ', 'cnpj', 1);

        $gravadas = $this->salvaFontes($edital, $this->planilha('modelo-pontos-de-cultura'), [
            'cnpj' => ['Pessoa Jurídica' => "campo:{$cnpj}", 'MEI' => 'campo:999999', 'Coletivo' => null, 'Cooperativa' => "campo:{$cnpj}"],
        ]);

        $this->assertSame(['cnpj' => ['Pessoa Jurídica' => "campo:{$cnpj}"]], $gravadas);
    }

    public function testCampoDeAgenteValePeloDadoQueTraz(): void
    {
        $edital = $this->novoEdital();
        $this->addCampo($edital, 'CPF do agente', 'agent-owner-field', 1, ['entityField' => 'cpf']);
        $this->addCampo($edital, 'Endereço do agente', 'agent-owner-field', 2, ['entityField' => '@location']);
        $this->addCampo($edital, 'Nome do agente', 'agent-owner-field', 3, ['entityField' => 'name']);
        $this->addCampo($edital, 'Documento do agente', 'agent-owner-field', 4, ['entityField' => 'documento']);

        $tipos = array_column($this->service()->camposDaFicha($edital), 'tipoDoDado', 'title');

        $this->assertSame([
            'CPF do agente' => 'cpf',
            'Endereço do agente' => ResultadoDeParaService::TIPO_COMPOSTO,
            'Nome do agente' => 'text',
            'Documento do agente' => 'text',
        ], $tipos, 'metadado registrado como texto vale como texto');
    }

    public function testDocumentoRecusaCampoQueNaoGuardaDocumentoComOMotivo(): void
    {
        $edital = $this->novoEdital();
        $selecao = $this->addCampo($edital, 'Trata-se de pessoa com deficiência?', 'select', 1);
        $endereco = $this->addCampo($edital, 'Endereço do agente', 'agent-owner-field', 2, ['entityField' => '@location']);
        $cpfDoAgente = $this->addCampo($edital, 'CPF do agente', 'agent-owner-field', 3, ['entityField' => 'cpf']);
        $texto = $this->addCampo($edital, 'Documento', 'text', 4);
        $cpf = $this->addCampo($edital, 'CPF', 'cpf', 5);

        $gravado = $this->service()->salvar($edital, $this->planilha('modelo-pontos-de-cultura'), [
            'cpf' => ['Pessoa Física' => "campo:{$selecao}", 'MEI' => "campo:{$endereco}", 'Coletivo' => "campo:{$cpfDoAgente}", 'Pessoa Jurídica' => "campo:{$texto}"],
            'cnpj' => ['Pessoa Jurídica' => "campo:{$cpf}"],
        ]);

        $this->assertSame(['cpf' => ['Coletivo' => "campo:{$cpfDoAgente}", 'Pessoa Jurídica' => "campo:{$texto}"]], $gravado['fontes']);
        $motivo = 'O tipo deste campo não serve a este documento.';
        $this->assertSame(['cnpj.Pessoa Jurídica' => $motivo, 'cpf.Pessoa Física' => $motivo, 'cpf.MEI' => $motivo], $gravado['recusas']);
    }

    public function testCampoRecusadoMantemOQueEstavaGravado(): void
    {
        $edital = $this->novoEdital();
        $cpf = $this->addCampo($edital, 'CPF', 'cpf', 1);
        $selecao = $this->addCampo($edital, 'Raça/etnia', 'select', 2);
        $planilha = $this->planilha('modelo-pontos-de-cultura');
        $this->salvaFontes($edital, $planilha, ['cpf' => ['Coletivo' => "campo:{$cpf}"]]);

        $gravado = $this->service()->salvar($edital, $planilha, ['cpf' => ['Coletivo' => "campo:{$selecao}"]]);

        $this->assertSame(['cpf' => ['Coletivo' => "campo:{$cpf}"]], $gravado['fontes'], 'a escolha recusada não apaga a anterior');
        $this->assertArrayHasKey('cpf.Coletivo', $gravado['recusas']);
    }

    public function testFontesDeOutroModeloOuDeOutraOportunidadeSaoIgnoradas(): void
    {
        $edital = $this->novoEdital();
        $copia = $this->novoEdital();
        $this->salvaFontes($edital, $this->planilha('modelo-pontos-de-cultura'), ['cota' => 'inscricao:faixa']);

        $this->assertSame([], $this->service()->fontes((int) $edital->id, 'premio_cultura_viva'));
        $this->assertSame([], $this->service()->fontes((int) $edital->id, null));

        $this->app->em->getConnection()->executeStatement(
            'INSERT INTO opportunity_meta (object_id, key, value) SELECT :copia, key, value FROM opportunity_meta WHERE object_id = :id AND key = :key',
            ['copia' => (int) $copia->id, 'id' => (int) $edital->id, 'key' => Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_PLANILHA_DE_PARA],
        );

        $this->assertSame([], $this->service()->fontes((int) $copia->id, 'pontos_de_cultura'), 'duplicar edital copia o metadado');
    }

    public function testGravarDeNovoSubstituiOAnterior(): void
    {
        $edital = $this->novoEdital();
        $planilha = $this->planilha('modelo-pontos-de-cultura');
        $this->salvaFontes($edital, $planilha, ['cota' => 'inscricao:faixa']);
        $this->salvaFontes($edital, $planilha, ['tipo_agente' => 'inscricao:tipo']);

        $this->assertSame(['tipo_agente' => 'inscricao:tipo'], $this->service()->fontes((int) $edital->id, 'pontos_de_cultura'));
    }

    /** Campo com opções, na forma em que a ficha as guarda. */
    private function addCampoComOpcoes(Opportunity $edital, string $tipo, array $opcoes, array $config = []): int
    {
        $id = $this->addCampo($edital, 'Campo com opções', $tipo, 1);
        $campo = $this->app->repo('RegistrationFieldConfiguration')->find($id);

        $this->app->disableAccessControl();
        $campo->fieldOptions = $opcoes;
        $campo->config = $config;
        $campo->save(true);
        $this->app->enableAccessControl();

        return $id;
    }

    #[DataProvider('camposComValores')]
    public function testValoresSemOpcaoVemDaConfiguracaoDoCampo(string $tipo, array $opcoes, array $esperados, array $config = []): void
    {
        $edital = $this->novoEdital();
        $campo = $this->addCampoComOpcoes($edital, $tipo, $opcoes, $config);

        $semOpcao = $this->service()->valoresSemOpcao($edital, $this->planilha('modelo-pontos-de-cultura'), ['raca_etnia' => "campo:{$campo}"]);

        $this->assertSame($esperados === [] ? [] : ['raca_etnia' => $esperados], $semOpcao);
    }

    public static function camposComValores(): array
    {
        return [
            'seleção' => ['select', ['Parda', 'parda', 'Negra ', 'Negra', ':Não informado'], ['parda', 'Negra']],
            'seleção com valor e rótulo' => ['select', ['pd:Parda', 'pt:Preta'], ['pd', 'pt']],
            'campo do agente' => ['agent-owner-field', ['Branca', 'Não informada'], ['Não informada'], ['entityField' => 'raca']],
            'campo do agente de vários valores' => ['agent-owner-field', ['Auditiva'], [], ['entityField' => 'pessoaDeficiente']],
            'múltipla escolha' => ['checkboxes', ['Negra'], []],
            'texto livre' => ['text', [], []],
            'todas são opção' => ['select', ['Parda', 'Preta'], []],
        ];
    }

    public function testValoresSemOpcaoDosDadosDaInscricao(): void
    {
        $edital = $this->novoEdital();
        $edital->registrationRanges = [['label' => 'Zona rural', 'limit' => 1, 'value' => 10], ['label' => 'Reserva de vagas para pessoas negras', 'limit' => 1, 'value' => 10]];
        $fontes = ['tipo_agente' => 'inscricao:tipo', 'cota' => 'inscricao:faixa', 'raca_etnia' => 'inscricao:valor', 'valor_total_subsidio' => 'inscricao:faixa', 'cpf' => ['MEI' => 'campo:1']];

        $this->assertSame(
            ['tipo_agente' => $this->service()->tiposDeProponente(), 'cota' => ['Zona rural']],
            $this->service()->valoresSemOpcao($edital, $this->planilha('modelo-pontos-de-cultura'), $fontes),
        );
    }

    public function testCampoApagadoNaoTemValores(): void
    {
        $this->assertSame([], $this->service()->valoresSemOpcao($this->novoEdital(), $this->planilha('modelo-pontos-de-cultura'), ['raca_etnia' => 'campo:999999999']));
    }

    public function testEquivalenciaSoValeParaValorDaFonteEOpcaoDoModelo(): void
    {
        $edital = $this->novoEdital();
        $planilha = $this->planilha('modelo-pontos-de-cultura');
        $fontes = ['tipo_agente' => 'inscricao:tipo', 'cota' => 'inscricao:faixa'];

        $gravado = $this->service()->salvar($edital, $planilha, $fontes, [
            'tipo_agente' => [
                'Pessoa Física' => 'Pessoa física',
                'MEI' => 'Opção que o modelo não tem',
                'Cooperativa' => 'Pessoa física',
                'Coletivo' => '',
            ],
            'cota' => ['Zona rural' => 'Reserva de vagas para outros grupos'],
            'raca_etnia' => ['Negra' => 'Preta'],
            'coluna_inventada' => ['x' => 'y'],
        ]);

        $this->assertSame(['tipo_agente' => ['Pessoa Física' => 'Pessoa física']], $gravado['equivalencias']);
        $this->assertSame($gravado['equivalencias'], $this->service()->equivalencias((int) $edital->id, 'pontos_de_cultura'));
        $this->assertSame([], $this->service()->equivalencias((int) $edital->id, 'premio_cultura_viva'));
    }

    public function testTrocarAFonteDescartaAEquivalenciaDela(): void
    {
        $edital = $this->novoEdital();
        $planilha = $this->planilha('modelo-pontos-de-cultura');
        $equivalencias = ['tipo_agente' => ['MEI' => 'Microempreendedor individual - MEI']];
        $this->service()->salvar($edital, $planilha, ['tipo_agente' => 'inscricao:tipo'], $equivalencias);

        $this->assertSame([], $this->service()->salvar($edital, $planilha, ['tipo_agente' => 'inscricao:faixa'], $equivalencias)['equivalencias']);
    }
}
