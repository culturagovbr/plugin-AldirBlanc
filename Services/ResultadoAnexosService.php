<?php

namespace AldirBlanc\Services;

use MapasCulturais\App;
use MapasCulturais\Definitions\FileGroup;
use MapasCulturais\Entities\File;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\OpportunityFile;
use MapasCulturais\i;

/** Documentos do resultado: arquivos privados do edital, ligados à questão pela resposta gravada. */
final class ResultadoAnexosService
{
    public const GRUPO = 'cultBrResultadoDocumento';

    /** Teto do contrato por arquivo. */
    private const TAMANHO_MAXIMO_MB = 10;

    private const BYTES_POR_MB = 1024 * 1024;

    private readonly FormularioResultadoService $formulario;

    public function __construct(private readonly Opportunity $raiz, private readonly array $estrutura)
    {
        $this->formulario = new FormularioResultadoService();
    }

    /** Guarda o arquivo na questão. @return string|null o motivo da recusa */
    public function anexar(int $idQuestao, File $arquivo): ?string
    {
        $motivo = $this->recusaDaQuestao($idQuestao) ?? $this->recusaDoArquivo($this->documentos()[$idQuestao], $arquivo);

        if ($motivo !== null) {
            return $motivo;
        }

        $arquivo->owner = $this->raiz;
        $arquivo->group = self::GRUPO;
        $arquivo->save(true);

        $this->grava($idQuestao, [...$this->idsDa($idQuestao), $arquivo->id]);

        return null;
    }

    /** Retira o arquivo da questão e o apaga. @return bool false quando ele não é dela */
    public function retirar(int $idQuestao, int $idArquivo): bool
    {
        $arquivo = $this->porQuestao()[$idQuestao][$idArquivo] ?? null;

        if ($arquivo === null) {
            return false;
        }

        $this->grava($idQuestao, array_values(array_diff($this->idsDa($idQuestao), [$idArquivo])));
        $arquivo->delete(true);

        return true;
    }

    /** Apaga os documentos que nenhuma resposta vigente cita. */
    public function retirarSemQuestao(): void
    {
        foreach (array_diff_key($this->doEdital(), ...array_values($this->porQuestao())) as $arquivo) {
            $arquivo->delete(true);
        }
    }

    /** @return array<int, array<int, OpportunityFile>> arquivos do próprio edital, por id, em cada questão */
    public function porQuestao(): array
    {
        $respostas = array_intersect_key($this->formulario->savedRespostas((int) $this->raiz->id, $this->estrutura), $this->documentos());

        if ($respostas === []) {
            return [];
        }

        $doEdital = $this->doEdital();

        return array_filter(array_map(fn(mixed $ids) => array_intersect_key($doEdital, array_flip(array_filter((array) $ids, is_int(...)))), $respostas));
    }

    /** @return array<int, list<array{id: int, nome: string, url: string}>> */
    public function paraATela(): array
    {
        return array_map(
            fn(array $arquivos) => array_map(
                fn(OpportunityFile $arquivo) => ['id' => $arquivo->id, 'nome' => $arquivo->name, 'url' => $arquivo->url],
                array_values($arquivos),
            ),
            $this->porQuestao(),
        );
    }

    /** Documento da questão como o envio o leva. @return array{nome: string, caminho: string, tipo: string, descricao: string, hash: string} */
    public static function paraOEnvio(OpportunityFile $arquivo, array $questao): array
    {
        $caminho = $arquivo->getPath();

        if (!is_file($caminho)) {
            throw new \RuntimeException("Documento {$arquivo->id} não está no disco");
        }

        return [
            'nome' => $arquivo->name,
            'caminho' => $caminho,
            'tipo' => $arquivo->mimeType,
            'descricao' => (string) ($questao['nome'] ?? ''),
            'hash' => hash_file('sha256', $caminho),
        ];
    }

    private function recusaDaQuestao(int $idQuestao): ?string
    {
        $respostas = $this->formulario->respostasVigentes($this->raiz, $this->estrutura);
        $validator = new FormularioRespostasValidator($this->estrutura);

        if (!isset($validator->documentosVisiveis($respostas)[$idQuestao])) {
            return i::__('Esta questão não recebe arquivo.');
        }

        $planilha = (new ModeloPlanilhaService())->daOportunidade((int) $this->raiz->id);

        return FormularioResultadoService::isDependenteDoCultBr($planilha, $validator->documentosRequeridos($respostas))
            ? FormularioResultadoService::motivoPlanilhaComDocumentos()
            : null;
    }

    private function recusaDoArquivo(array $questao, File $arquivo): ?string
    {
        $formatos = $questao['config']['formatos'] ?? [];
        $limite = min($questao['config']['tamanhoMaxMb'] ?? self::TAMANHO_MAXIMO_MB, self::TAMANHO_MAXIMO_MB);

        return match (true) {
            $formatos !== [] && !$this->isFormatoAceito($formatos, $arquivo) => sprintf(i::__('Formatos aceitos: %s.'), implode(', ', $formatos)),
            $arquivo->tmpFile['size'] > $limite * self::BYTES_POR_MB => sprintf(i::__('O arquivo passa de %s MB.'), $limite),
            !$this->isMultipla($questao) && $this->idsDa((int) $questao['id_questao']) !== [] => i::__('Esta questão aceita um arquivo só. Retire o atual para anexar outro.'),
            default => $this->recusaDoGrupo($arquivo),
        };
    }

    private function isFormatoAceito(array $formatos, File $arquivo): bool
    {
        return in_array('.' . strtolower(pathinfo($arquivo->name, PATHINFO_EXTENSION)), array_map(strtolower(...), $formatos), true);
    }

    private function isMultipla(array $questao): bool
    {
        return !empty($questao['config']['multiplos']) || $questao['tipo_questao'] === FormularioEstrutura::MULTIPLOS_ANEXOS;
    }

    /** As mesmas conferências que o core faz em todo upload. */
    private function recusaDoGrupo(File $arquivo): ?string
    {
        $grupo = App::i()->getRegisteredFileGroup('opportunity', self::GRUPO);
        $invalidacoes = array_merge(...array_values(array_map(array_values(...), $arquivo->getValidationErrors())));

        return $grupo->getExtensionError($arquivo) ?: implode(', ', $invalidacoes) ?: $grupo->getError($arquivo) ?: null;
    }

    /** @return list<int> */
    private function idsDa(int $idQuestao): array
    {
        return array_keys($this->porQuestao()[$idQuestao] ?? []);
    }

    private function grava(int $idQuestao, array $ids): void
    {
        $this->formulario->gravaRespostas($this->raiz, [$idQuestao => $ids ?: null], $this->estrutura);
    }

    /** @return array<int, array> questões de documento, por id_questao */
    public function documentos(): array
    {
        return array_filter((new FormularioEstrutura($this->estrutura))->questoes(), FormularioEstrutura::isDocumento(...));
    }

    /** @return array<int, OpportunityFile> */
    private function doEdital(): array
    {
        $arquivos = App::i()->repo('OpportunityFile')->findBy(['owner' => $this->raiz, 'group' => self::GRUPO], ['id' => 'ASC']);

        return array_column($arquivos, null, 'id');
    }
}
