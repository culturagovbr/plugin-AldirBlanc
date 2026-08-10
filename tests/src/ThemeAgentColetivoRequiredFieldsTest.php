<?php

namespace Tests\AldirBlanc;

use MapasCulturais\Entities\Agent;
use Tests\Abstract\TestCase;
use Tests\Traits\AgentDirector;
use Tests\Traits\RequestFactory;
use Tests\Traits\UserDirector;

/**
 * Campos obrigatórios do agente coletivo no Theme.php do tema Pnab.
 *
 * Coletivos e grupos informais não possuem personalidade jurídica, então os campos de
 * pessoa jurídica não são exigidos deles. Para os demais tipos de agente coletivo nada
 * muda: continuam obrigatórios.
 */
class ThemeAgentColetivoRequiredFieldsTest extends TestCase
{
    use UserDirector, AgentDirector, RequestFactory;

    private const METADATA_PESSOA_JURIDICA = ['nomeSocial', 'nomeCompleto', 'cnpj', 'dataDeNascimento'];

    private const METADATA_DE_TODO_AGENTE_COLETIVO = [
        'emailPrivado',
        'telefonePublico',
        'emailPublico',
        'En_CEP',
        'En_Nome_Logradouro',
        'En_Num',
        'En_Bairro',
        'En_Municipio',
        'En_Estado',
    ];

    /**
     * Cria um agente coletivo persistido com os campos obrigatórios vazios.
     *
     * O tipoAgenteColetivo é gravado antes do primeiro save porque a validação só roda
     * em agente que já existe, e o tema impede a alteração do metadado depois disso.
     *
     * Devolve a instância recarregada do banco: sem isso o __metadata não é iterável e
     * getMetadataValidationErrors não encontra metadado nenhum para validar.
     *
     * O dono fica logado porque os campos em questão são privados, e getRegisteredMetadata
     * descarta metadado privado de quem não pode ver dado privado — sem login a validação
     * não enxergaria campo nenhum.
     */
    private function createAgenteColetivo(string $tipoAgenteColetivo): Agent
    {
        $user = $this->userDirector->createUser();
        $agent = $this->agentDirector->createAgent($user, 2, save: false);

        $this->app->disableAccessControl();
        $agent->setMetadata('tipoAgenteColetivo', $tipoAgenteColetivo);
        $agent->save(true);
        $this->app->enableAccessControl();

        $this->login($user);

        return $agent->refreshed();
    }

    public function testColetivoInformalNaoEhCobradoPorCamposDePessoaJuridica(): void
    {
        $agent = $this->createAgenteColetivo('coletivos_grupos_informais');

        $errors = $agent->getValidationErrors();

        foreach (self::METADATA_PESSOA_JURIDICA as $key) {
            $this->assertArrayNotHasKey($key, $errors, "O campo {$key} não deveria ser exigido de coletivos e grupos informais");
        }
    }

    public function testColetivoComPersonalidadeJuridicaContinuaSendoCobrado(): void
    {
        $agent = $this->createAgenteColetivo('pj_fins_lucrativos');

        $errors = $agent->getValidationErrors();

        foreach (self::METADATA_PESSOA_JURIDICA as $key) {
            $this->assertArrayHasKey($key, $errors, "O campo {$key} deveria continuar obrigatório para agente coletivo com personalidade jurídica");
        }
    }

    public function testColetivoInformalContinuaSendoCobradoPelosDemaisCampos(): void
    {
        $agent = $this->createAgenteColetivo('coletivos_grupos_informais');

        $errors = $agent->getValidationErrors();

        foreach (self::METADATA_DE_TODO_AGENTE_COLETIVO as $key) {
            $this->assertArrayHasKey($key, $errors, "O campo {$key} deveria continuar obrigatório para coletivos e grupos informais");
        }
    }

    /**
     * Fluxo relatado: a edição do agente envia só o campo alterado, e o hook do payload
     * completava a requisição com os obrigatórios do agente coletivo.
     */
    public function testPatchParcialEmColetivoInformalNaoDevolveErroDeCampoDePessoaJuridica(): void
    {
        $agent = $this->createAgenteColetivo('coletivos_grupos_informais');

        $errors = $this->patchAgent($agent, ['anosExperienciaAreaCultural' => 0]);

        foreach (self::METADATA_PESSOA_JURIDICA as $key) {
            $this->assertArrayNotHasKey($key, $errors, "O PATCH não deveria cobrar {$key} de coletivos e grupos informais");
        }
    }

    public function testPatchParcialEmColetivoComPersonalidadeJuridicaContinuaCobrando(): void
    {
        $agent = $this->createAgenteColetivo('pj_fins_lucrativos');

        $errors = $this->patchAgent($agent, ['anosExperienciaAreaCultural' => 0]);

        foreach (self::METADATA_PESSOA_JURIDICA as $key) {
            $this->assertArrayHasKey($key, $errors, "O PATCH deveria continuar cobrando {$key} de agente coletivo com personalidade jurídica");
        }
    }

    /**
     * @return array<string, array<int, string>> Erros por campo devolvidos pela requisição
     */
    private function patchAgent(Agent $agent, array $payload): array
    {
        $request = $this->requestFactory->PATCH('agent', 'single', [$agent->id], payload: $payload);

        $this->login($agent->user);
        $this->app->run($request, false);

        $body = json_decode((string) $this->app->response->getBody(), true);

        return $body['data'] ?? [];
    }
}
