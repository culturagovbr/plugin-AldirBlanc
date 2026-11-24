<?php

namespace AldirBlanc\Services;

use AldirBlanc\Controller;
use MapasCulturais\App;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\RegistrationFieldConfiguration;
use MapasCulturais\Entities\RegistrationMeta;

/**
 * Beneficiários de um edital para a grade de resultado: pagina as contempladas da fase final,
 * resolve cada linha pelas fontes do sistema e guarda na inscrição de origem o que o gestor digitou.
 */
class BeneficiariosService
{
    /** Um campo só pode guardar CPF ou CNPJ: o tamanho sem a máscara diz a qual das duas colunas ele serve. */
    private const TAMANHO_DO_OUTRO_DOCUMENTO = ['cpf' => 14, 'cnpj' => 11];

    private LinhasBeneficiariosService $linhas;
    private ResultadoDeParaService $dePara;

    /** @param array{modelo: ?string, colunas: list<array>} $planilha modelo de planilha do edital */
    public function __construct(private readonly array $planilha)
    {
        $this->linhas = new LinhasBeneficiariosService($planilha['colunas']);
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

    /**
     * Todas as contempladas em lotes, na mesma ordem; `pagina` recontaria o total a cada passo.
     * Solta as inscrições a cada lote: quem chamar não pode estar segurando nenhuma.
     */
    public function emLotes(Opportunity $raiz, int $tamanho, callable $aCadaLote): void
    {
        $faseFinal = $this->faseFinalPublicada($raiz);
        if ($faseFinal === null) {
            return;
        }

        $repo = App::i()->repo('Registration');
        $criterio = ['opportunity' => $faseFinal, 'status' => Registration::STATUS_APPROVED];

        for ($skip = 0; ; $skip += $tamanho) {
            $contempladas = $repo->findBy($criterio, ['number' => 'ASC'], $tamanho, $skip);
            if ($contempladas === []) {
                return;
            }

            $aCadaLote($this->montar($raiz, $contempladas));
            $this->liberaInscricoesHidratadas();
        }
    }

    /** Solta só inscrição e metadado: um `clear` geral destacaria o edital que o chamador ainda usa. */
    private function liberaInscricoesHidratadas(): void
    {
        $em = App::i()->em;
        $mapa = $em->getUnitOfWork()->getIdentityMap();

        foreach ([Registration::class, RegistrationMeta::class] as $classe) {
            foreach ($mapa[$classe] ?? [] as $entidade) {
                $em->detach($entidade);
            }
        }
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
        $fontes = $this->dePara->fontes((int) $raiz->id, $this->planilha['modelo']);
        $campos = $this->camposDasFontes($fontes);
        $equivalencias = $this->dePara->equivalencias((int) $raiz->id, $this->planilha['modelo']);
        $faixas = new FaixasDoEdital($raiz->registrationRanges);
        $inscricoes = $this->inscricoesPorNumero($raiz, $campos, $contempladas);

        $linhas = [];
        foreach ($contempladas as $contemplada) {
            $porFase = $inscricoes[$contemplada->number] ?? [];
            $daFonte = [];

            foreach ($this->planilha['colunas'] as $coluna) {
                $valor = $this->valorDaFonte($fontes[$coluna['coluna']] ?? '', $contemplada, $porFase, $campos, $faixas);
                $daFonte[$coluna['coluna']] = $this->semDocumentoDeOutroTipo($coluna['tipo'], $valor);
            }

            $linhas[] = $this->linha($contemplada, $this->ancora($raiz, $contemplada, $porFase), $daFonte, $equivalencias);
        }

        return $linhas;
    }

    /** Grava na inscrição de origem o que o gestor digitou e devolve a linha recalculada, ou null se não é contemplada. */
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

        $porFase = $this->inscricoesPorNumero($raiz, [], [$contemplada])[$contemplada->number] ?? [];
        $ancora = $this->ancora($raiz, $contemplada, $porFase);
        $this->gravaOverride($ancora, $this->celulasAceitas($celulas, $this->override($ancora)));

        return $this->montar($raiz, [$contemplada])[0];
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

    private function linha(Registration $contemplada, Registration $ancora, array $daFonte, array $equivalencias): array
    {
        $mesclada = $this->linhas->mesclar($daFonte, $this->override($ancora), $equivalencias);

        return [
            'registrationId' => (int) $contemplada->id,
            'origemId' => (int) $ancora->id,
            'number' => (string) $contemplada->number,
            'celulas' => $mesclada,
            'pendencias' => $this->linhas->pendencias($mesclada),
        ];
    }

    /** @return array<string, RegistrationFieldConfiguration> os campos da ficha que o de/para aponta, pela fonte */
    private function camposDasFontes(array $fontes): array
    {
        $ids = [];
        array_walk_recursive($fontes, function (string $fonte) use (&$ids) {
            if (str_starts_with($fonte, ResultadoDeParaService::FONTE_CAMPO)) {
                $ids[] = (int) substr($fonte, strlen(ResultadoDeParaService::FONTE_CAMPO));
            }
        });

        $campos = [];
        foreach ($ids === [] ? [] : App::i()->repo('RegistrationFieldConfiguration')->findBy(['id' => $ids]) as $campo) {
            $campos[ResultadoDeParaService::FONTE_CAMPO . $campo->id] = $campo;
        }

        return $campos;
    }

    /** O valor da fonte para esta contemplada; fonte por tipo de proponente é de documento. */
    private function valorDaFonte(string|array $fonte, Registration $contemplada, array $inscricoesPorFase, array $campos, FaixasDoEdital $faixas): mixed
    {
        if (is_array($fonte)) {
            $fonte = $fonte[(string) $contemplada->proponentType] ?? '';
        }

        return match ($fonte) {
            ResultadoDeParaService::FONTE_TIPO_DE_PROPONENTE => $contemplada->proponentType,
            ResultadoDeParaService::FONTE_FAIXA => $contemplada->range,
            ResultadoDeParaService::FONTE_VALOR_POR_CONTEMPLADO => $faixas->valorPorContemplado(trim((string) $contemplada->range)),
            default => $this->daFicha($campos[$fonte] ?? null, $inscricoesPorFase),
        };
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

    /** A resposta da inscrição da fase em que o campo está. */
    private function daFicha(?RegistrationFieldConfiguration $campo, array $inscricoesPorFase): mixed
    {
        // Qualquer inscrição da cadeia devolveria o valor, mas por uma consulta do core a cada célula.
        $inscricao = $campo ? $inscricoesPorFase[(int) $campo->owner->id] ?? null : null;

        return $inscricao?->{$campo->fieldName};
    }

    private function semDocumentoDeOutroTipo(string $tipo, mixed $respondido): mixed
    {
        $isDeOutroTipo = is_scalar($respondido)
            && strlen(FormularioMascara::semPontuacao((string) $respondido)) === (self::TAMANHO_DO_OUTRO_DOCUMENTO[$tipo] ?? null);

        return $isDeOutroTipo ? null : $respondido;
    }

    /** Só coluna do modelo entra no override; o vazio é gravado porque apagar é decisão do gestor. */
    private function celulasAceitas(array $recebidas, array $override): array
    {
        foreach (array_column($this->planilha['colunas'], 'coluna') as $coluna) {
            if (array_key_exists($coluna, $recebidas) && !is_array($recebidas[$coluna])) {
                $override[$coluna] = (string) $recebidas[$coluna];
            }
        }

        return $override;
    }

    private function gravaOverride(Registration $ancora, array $override): void
    {
        $app = App::i();

        // Inscrição fora de rascunho recusa 'modify', o que faria a segunda gravação do gestor falhar.
        $app->disableAccessControl();

        try {
            $ancora->{Controller::REGISTRATION_META_CULT_BR_RESULTADO_PLANILHA_LINHA} = ['modelo' => $this->planilha['modelo'], 'celulas' => $override];
            $ancora->saveMetadata(true);
        } finally {
            $app->enableAccessControl();
        }
    }

    /** O que o gestor digitou para este modelo; célula de outro modelo é ignorada. */
    private function override(Registration $ancora): array
    {
        // A propriedade mágica desserializa o json do metadado, e devolve objeto onde queremos array.
        $salvo = (array) $ancora->{Controller::REGISTRATION_META_CULT_BR_RESULTADO_PLANILHA_LINHA};

        return ($salvo['modelo'] ?? null) === $this->planilha['modelo'] ? (array) ($salvo['celulas'] ?? []) : [];
    }
}
