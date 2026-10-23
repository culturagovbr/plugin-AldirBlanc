<?php

namespace Tests\AldirBlanc\Traits;

use AldirBlanc\Controller;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\RegistrationFieldConfiguration;
use MapasCulturais\Entities\RegistrationStep;

/** Edital com resultado publicado e inscrições contempladas, como a grade de beneficiários encontra. */
trait BuildsContempladas
{
    protected function editalComCategorias(array $categorias = []): array
    {
        $owner = $this->userDirector->createUser();
        [$raiz, $fase] = $this->editalComFaseFinalPublicada($owner, 'Subsite Beneficiarios ' . uniqid());

        if ($categorias !== []) {
            $this->app->disableAccessControl();
            $raiz->registrationRanges = $categorias;
            $raiz->save(true);
            $this->app->enableAccessControl();
        }

        return [$raiz, $fase, $owner];
    }

    /**
     * Inscrição contemplada: a de origem guarda o cadastro do agente, a da fase final guarda o desfecho.
     * É o que o core faz ao promover de fase — sem copiar o snapshot dos agentes.
     */
    protected function contemplada(Opportunity $raiz, Opportunity $fase, array $agentsData, string $proponente = 'Pessoa Física', string $faixa = ''): Registration
    {
        $this->app->disableAccessControl();

        $origem = $this->inscricao($raiz, $agentsData, $proponente, $faixa);
        $this->mudaStatus($origem, Registration::STATUS_SENT);
        $contemplada = $this->inscricao($fase, [], $proponente, $faixa);
        $this->promove($contemplada, (string) $origem->number);

        $this->app->enableAccessControl();

        return $contemplada;
    }

    /** Os campos entram antes do insert: alterá-los depois não chega ao banco. */
    protected function inscricao(Opportunity $opportunity, array $agentsData, string $proponente, string $faixa): Registration
    {
        $inscricao = new Registration();
        $inscricao->opportunity = $opportunity;
        $inscricao->owner = $opportunity->owner;
        $inscricao->agentsData = $agentsData;
        $inscricao->proponentType = $proponente;
        $inscricao->range = $faixa;
        $inscricao->save(true);

        return $inscricao;
    }

    /** O número herdado da origem e o desfecho aprovado, como o core deixa a inscrição da fase final. */
    protected function promove(Registration $inscricao, string $number): void
    {
        $this->app->em->getConnection()->executeStatement(
            'UPDATE registration SET number = :number, status = :status WHERE id = :id',
            ['number' => $number, 'status' => Registration::STATUS_APPROVED, 'id' => (int) $inscricao->id],
        );
        $this->app->em->refresh($inscricao);
    }

    /** Fora de rascunho é o que importa: é aí que o core passa a recusar alteração na inscrição. */
    protected function mudaStatus(Registration $inscricao, int $status): void
    {
        $this->app->em->getConnection()->executeStatement(
            'UPDATE registration SET status = :status WHERE id = :id',
            ['status' => $status, 'id' => (int) $inscricao->id],
        );
        $this->app->em->refresh($inscricao);
    }

    protected function campoDaFicha(Opportunity $opportunity, string $title, string $tipo = 'text'): RegistrationFieldConfiguration
    {
        $this->app->disableAccessControl();

        // Campo sem etapa estoura no core ao ler a visibilidade: isStepVisible não aceita null.
        $etapa = new RegistrationStep();
        $etapa->name = 'Etapa única';
        $etapa->displayOrder = 1;
        $etapa->opportunity = $opportunity;
        $etapa->save(true);

        $campo = new RegistrationFieldConfiguration();
        $campo->owner = $opportunity;
        $campo->step = $etapa;
        $campo->title = $title;
        $campo->fieldType = $tipo;
        $campo->displayOrder = 1;
        $campo->save(true);
        $this->app->enableAccessControl();

        return $campo;
    }

    /** Um campo de documento por edital, reaproveitado entre as contempladas do mesmo cenário. */
    private array $camposDeDocumentoPorEdital = [];

    /** Contemplada cujo documento do responsável já está respondido no campo que o de/para aponta. */
    protected function contempladaComDocumento(Opportunity $raiz, Opportunity $fase, string $documento, string $proponente = 'Pessoa Física', string $faixa = ''): Registration
    {
        $campo = $this->camposDeDocumentoPorEdital[(int) $raiz->id]
            ??= $this->campoDeDocumento($raiz, $raiz, $this->app->config['registration.proponentTypes']);

        $contemplada = $this->contemplada($raiz, $fase, [], $proponente, $faixa);
        $this->respondeFicha($raiz, (string) $contemplada->number, $campo, $documento);

        return $contemplada;
    }

    /** O de/para do documento do responsável pela execução: tipo de proponente => id do campo da ficha. */
    protected function mapeiaDocumentos(Opportunity $raiz, array $porTipo): void
    {
        $conn = $this->app->em->getConnection();
        $parametros = ['id' => (int) $raiz->id, 'key' => Controller::OPPORTUNITY_META_CULT_BR_RESULTADO_DOCUMENTO_POR_TIPO];

        $conn->executeStatement('DELETE FROM opportunity_meta WHERE object_id = :id AND key = :key', $parametros);
        $conn->executeStatement(
            'INSERT INTO opportunity_meta (object_id, key, value) VALUES (:id, :key, :value)',
            $parametros + ['value' => json_encode($porTipo, JSON_UNESCAPED_UNICODE)],
        );
    }

    /** Campo de documento na fase indicada, já apontado pelo de/para dos tipos informados. */
    protected function campoDeDocumento(Opportunity $raiz, Opportunity $fase, array $tipos, string $title = 'Documento do responsável'): RegistrationFieldConfiguration
    {
        $campo = $this->campoDaFicha($fase, $title);
        $this->mapeiaDocumentos($raiz, array_fill_keys($tipos, (int) $campo->id));

        return $campo;
    }

    /** Gravado por SQL de propósito: registrar o metadado aqui esconderia que o serviço precisa fazê-lo. */
    protected function respondeFicha(Opportunity $opportunity, string $number, RegistrationFieldConfiguration $campo, mixed $valor): void
    {
        $conn = $this->app->em->getConnection();
        $inscricaoId = (int) $conn->fetchOne(
            'SELECT id FROM registration WHERE opportunity_id = :opportunity AND number = :number',
            ['opportunity' => (int) $opportunity->id, 'number' => $number],
        );

        $conn->executeStatement(
            "INSERT INTO registration_meta (id, object_id, key, value) VALUES (nextval('registration_meta_id_seq'::regclass), :object, :key, :value)",
            ['object' => $inscricaoId, 'key' => $campo->fieldName, 'value' => is_array($valor) ? json_encode($valor) : (string) $valor],
        );

        $emMemoria = $this->app->repo('Registration')->find($inscricaoId);
        if ($emMemoria) {
            $this->app->em->refresh($emMemoria);
        }
    }

    protected function celula(array $linha, string $coluna): mixed
    {
        return $linha['celulas'][$coluna]['valor'];
    }
}
