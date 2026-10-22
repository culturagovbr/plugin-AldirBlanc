<?php

namespace AldirBlanc\Services;

use AldirBlanc\Controller;
use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;

/**
 * Beneficiários de um edital para a grade de resultado: pagina as contempladas da fase final,
 * resolve cada linha pelas fontes do sistema e guarda na inscrição de origem o que o gestor digitou.
 */
class BeneficiariosService
{
    private const CPF_DIGITOS = 11;
    private const CNPJ_DIGITOS = 14;

    private LinhasBeneficiariosService $linhas;
    private ResultadoDeParaService $dePara;

    public function __construct()
    {
        $this->linhas = new LinhasBeneficiariosService();
        $this->dePara = new ResultadoDeParaService();
    }

    /** Página da grade: as linhas já mescladas e o total de contempladas do edital. */
    public function pagina(Opportunity $raiz, int $skip, int $limit): array
    {
        $faseFinal = $this->faseFinalPublicada($raiz);
        if ($faseFinal === null) {
            return ['linhas' => [], 'total' => 0];
        }

        $repo = App::i()->repo('Registration');
        $criterio = ['opportunity' => $faseFinal, 'status' => Registration::STATUS_APPROVED];

        $contempladas = $repo->findBy($criterio, ['number' => 'ASC'], $limit, $skip);

        return ['linhas' => $this->montar($raiz, $contempladas), 'total' => (int) $repo->count($criterio)];
    }

    /** Linhas das contempladas informadas, na ordem em que a tabela as listou. */
    public function linhasDe(Opportunity $raiz, array $registrationIds): array
    {
        $faseFinal = $this->faseFinalPublicada($raiz);
        if ($faseFinal === null || $registrationIds === []) {
            return [];
        }

        $contempladas = App::i()->repo('Registration')->findBy([
            'id' => $registrationIds,
            'opportunity' => $faseFinal,
            'status' => Registration::STATUS_APPROVED,
        ]);

        $posicao = array_flip(array_values($registrationIds));
        usort($contempladas, fn($a, $b) => ($posicao[(int) $a->id] ?? PHP_INT_MAX) <=> ($posicao[(int) $b->id] ?? PHP_INT_MAX));

        return $this->montar($raiz, $contempladas);
    }

    private function montar(Opportunity $raiz, array $contempladas): array
    {
        $campos = $this->camposMapeados($raiz);
        $inscricoes = $this->inscricoesPorNumero($raiz, $campos, $contempladas);

        $linhas = [];
        foreach ($contempladas as $contemplada) {
            $porFase = $inscricoes[$contemplada->number] ?? [];
            $linhas[] = $this->linha($raiz, $contemplada, $this->ancora($raiz, $contemplada, $porFase), $this->daFicha($campos, $porFase));
        }

        return $linhas;
    }

    /** Grava na inscrição de origem o que o gestor digitou e devolve a linha recalculada. */
    public function salvarCelulas(Opportunity $raiz, int $registrationId, array $celulas): ?array
    {
        $faseFinal = $this->faseFinalPublicada($raiz);
        if ($faseFinal === null) {
            return null;
        }

        $contemplada = App::i()->repo('Registration')->findOneBy([
            'id' => $registrationId,
            'opportunity' => $faseFinal,
            'status' => Registration::STATUS_APPROVED,
        ]);
        if ($contemplada === null) {
            return null;
        }

        $campos = $this->camposMapeados($raiz);
        $porFase = $this->inscricoesPorNumero($raiz, $campos, [$contemplada])[$contemplada->number] ?? [];
        $ancora = $this->ancora($raiz, $contemplada, $porFase);
        $daFicha = $this->daFicha($campos, $porFase);
        $candidato = $this->celulasAceitas($celulas, $this->override($ancora));

        $automatico = $this->automatico($raiz, $contemplada, $ancora);
        $jaEstavamForaDaOrdem = $this->linhas->datasForaDaOrdem($this->linhas->mesclar($automatico, $daFicha, $this->override($ancora)));
        $ficariamForaDaOrdem = $this->linhas->datasForaDaOrdem($this->linhas->mesclar($automatico, $daFicha, $candidato));

        // Recusa o que esta edição quebra, não o que já estava quebrado: senão a linha trava por inteiro.
        $recusadas = array_values(array_diff($ficariamForaDaOrdem, $jaEstavamForaDaOrdem));
        if ($recusadas !== []) {
            return ['recusadas' => $recusadas];
        }

        $this->gravaOverride($ancora, $candidato);

        return ['linha' => $this->linha($raiz, $contemplada, $ancora, $daFicha)];
    }

    /** Fase final publicada do edital, ou null quando o resultado ainda não saiu. */
    public function faseFinalPublicada(Opportunity $raiz): ?Opportunity
    {
        $faseFinal = $raiz->lastPhase;

        return ($faseFinal && $faseFinal->isLastPhase && $faseFinal->publishedRegistrations) ? $faseFinal : null;
    }

    /**
     * A inscrição que guarda o cadastro e recebe o que o gestor digita: a da primeira fase, por ser
     * a que sobrevive à recriação da fase final; em edital de fase única, a própria contemplada.
     */
    private function ancora(Opportunity $raiz, Registration $contemplada, array $inscricoesPorFase): Registration
    {
        return $inscricoesPorFase[(int) $raiz->id] ?? $contemplada;
    }

    private function linha(Opportunity $raiz, Registration $contemplada, Registration $ancora, array $daFicha): array
    {
        $mesclada = $this->linhas->mesclar(
            $this->automatico($raiz, $contemplada, $ancora),
            $daFicha,
            $this->override($ancora),
        );

        return [
            'registrationId' => (int) $contemplada->id,
            'origemId' => (int) $ancora->id,
            'number' => (string) $contemplada->number,
            'celulas' => $mesclada,
            'pendencias' => $this->linhas->pendencias($mesclada),
        ];
    }

    /** O campo da ficha que alimenta cada coluna mapeada, pelo de/para salvo no edital. */
    private function camposMapeados(Opportunity $raiz): array
    {
        $mapeamento = array_filter($this->dePara->mapeamento((int) $raiz->id));
        if ($mapeamento === []) {
            return [];
        }

        $configuracoes = [];
        foreach (App::i()->repo('RegistrationFieldConfiguration')->findBy(['id' => array_values($mapeamento)]) as $campo) {
            $configuracoes[(int) $campo->id] = $campo;
        }

        $porColuna = [];
        foreach ($mapeamento as $coluna => $id) {
            if (isset($configuracoes[(int) $id])) {
                $porColuna[$coluna] = $configuracoes[(int) $id];
            }
        }

        return $porColuna;
    }

    /** As inscrições das contempladas da página, por número e fase, numa consulta só. */
    private function inscricoesPorNumero(Opportunity $raiz, array $campos, array $contempladas): array
    {
        $numeros = [];
        foreach ($contempladas as $contemplada) {
            if ($contemplada->number) {
                $numeros[] = $contemplada->number;
            }
        }

        if ($numeros === []) {
            return [];
        }

        $oportunidades = [(int) $raiz->id => $raiz];
        foreach ($campos as $campo) {
            $fase = $campo->owner;
            $oportunidades[(int) $fase->id] = $fase;
            $fase->registerRegistrationMetadata();
        }

        $indice = [];
        foreach (App::i()->repo('Registration')->findBy(['opportunity' => array_values($oportunidades), 'number' => $numeros]) as $inscricao) {
            $indice[$inscricao->number][(int) $inscricao->opportunity->id] = $inscricao;
        }

        return $indice;
    }

    private function daFicha(array $campos, array $inscricoesPorFase): array
    {
        $valores = [];
        foreach ($campos as $coluna => $campo) {
            // Qualquer inscrição da cadeia devolveria o valor, mas por uma consulta do core a cada célula.
            $inscricao = $inscricoesPorFase[(int) $campo->owner->id] ?? null;
            if ($inscricao) {
                $valores[$coluna] = $inscricao->{$campo->fieldName};
            }
        }

        return $valores;
    }

    private function automatico(Opportunity $raiz, Registration $contemplada, Registration $ancora): array
    {
        // O snapshot dos agentes é gravado no envio e não é copiado para as fases seguintes.
        $agente = $this->agenteResponsavel($contemplada, $ancora->agentsData ?: []);

        $cpf = $this->documento($agente, self::CPF_DIGITOS);
        $cnpj = $this->documento($agente, self::CNPJ_DIGITOS);

        return [
            'tipo_agente' => $this->tipoDeAgente($cpf, $cnpj),
            'identificador_edital' => (string) $raiz->name,
            'cpf_responsavel_execucao' => $cpf,
            'cnpj_responsavel_execucao' => $cnpj,
            'valor_recebido_pnab' => $this->valorDaCategoria($raiz, $contemplada),
        ];
    }

    /**
     * PF e MEI respondem pelo próprio cadastro; coletivo e pessoa jurídica, pelo agente do grupo.
     * Sem o agente do grupo a linha fica sem documento, e não com o do representante.
     */
    private function agenteResponsavel(Registration $contemplada, array $agentsData): array
    {
        $mapa = App::i()->config['registration.proponentTypesToAgentsMap'] ?? [];
        $grupo = $mapa[(string) $contemplada->proponentType] ?? 'owner';

        return $agentsData[$grupo] ?? [];
    }

    /**
     * O CultBr só conhece as duas naturezas, com esta grafia; os tipos de proponente do edital
     * (MEI, Coletivo) não existem lá, e quem decide é o documento do responsável.
     */
    private function tipoDeAgente(?string $cpf, ?string $cnpj): string
    {
        if ($cnpj !== null) {
            return 'Pessoa jurídica';
        }

        return $cpf !== null ? 'Pessoa física' : '';
    }

    /** O documento vem mascarado no cadastro; a quantidade de dígitos diz se é CPF ou CNPJ. */
    private function documento(array $agente, int $digitos): ?string
    {
        $chave = $digitos === self::CPF_DIGITOS ? 'cpf' : 'cnpj';

        foreach ([$agente[$chave] ?? '', $agente['documento'] ?? ''] as $candidato) {
            $limpo = preg_replace('/\D/', '', (string) $candidato);
            if (strlen($limpo) === $digitos) {
                return $limpo;
            }
        }

        return null;
    }

    private function valorDaCategoria(Opportunity $raiz, Registration $contemplada): ?string
    {
        $faixa = trim((string) $contemplada->range);
        if ($faixa === '') {
            return null;
        }

        foreach ($raiz->registrationRanges ?: [] as $categoria) {
            $valor = $categoria['value'] ?? null;
            if (trim((string) ($categoria['label'] ?? '')) === $faixa && is_numeric($valor)) {
                return number_format((float) $valor, 2, '.', '');
            }
        }

        return null;
    }

    /** Coluna que a grade não edita é descartada, e célula apagada sai do override em vez de gravar vazio. */
    private function celulasAceitas(array $recebidas, array $override): array
    {
        foreach ($recebidas as $coluna => $valor) {
            $definicao = LinhasBeneficiariosService::COLUNAS[$coluna] ?? null;
            if ($definicao === null || !$definicao['editavel']) {
                continue;
            }

            // Guarda o vazio em vez de remover a chave: apagar é uma decisão do gestor, não ausência dela.
            $override[$coluna] = is_array($valor) ? $valor : (string) $valor;
        }

        return $override;
    }

    private function gravaOverride(Registration $ancora, array $override): void
    {
        $app = App::i();

        // Inscrição fora de rascunho recusa 'modify', o que faria a segunda gravação do gestor falhar.
        $app->disableAccessControl();

        try {
            $ancora->{Controller::REGISTRATION_META_CULT_BR_RESULTADO_LINHA} = $override;
            $ancora->saveMetadata(true);
        } finally {
            $app->enableAccessControl();
        }
    }

    private function override(Registration $ancora): array
    {
        // A propriedade mágica desserializa o json do metadado, e devolve objeto onde queremos array.
        $salvo = $ancora->{Controller::REGISTRATION_META_CULT_BR_RESULTADO_LINHA};

        return is_object($salvo) || is_array($salvo) ? (array) $salvo : [];
    }
}
