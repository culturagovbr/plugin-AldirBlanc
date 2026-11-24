<?php

namespace Tests\AldirBlanc\Traits;

use MapasCulturais\Entities\File;
use MapasCulturais\Entities\OpportunityFile;

/** Arquivos de upload e questões de documento do formulário de resultado. */
trait BuildsAnexos
{
    protected const PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";

    /** @var list<string> */
    private array $caminhosDosAnexos = [];

    protected function questaoDeDocumento(int $id, array $config = [], array $extras = []): array
    {
        return $extras + [
            'id_questao' => $id, 'id_questao_etapa' => $id + 10000, 'nome' => "Documento {$id}", 'tipo_questao' => 'anexo', 'tag' => "documento_{$id}",
            'requerida' => false, 'opcao' => null, 'config' => $config, 'somente_leitura' => false,
        ];
    }

    /** Entrada de $_FILES; o tamanho declarado dispensa gravar megabytes no disco. */
    protected function upload(string $nome, string $conteudo = self::PDF, ?int $tamanho = null): array
    {
        $caminho = tempnam(sys_get_temp_dir(), 'anexo');
        file_put_contents($caminho, $conteudo);
        $this->caminhosDosAnexos[] = $caminho;

        return ['name' => $nome, 'type' => '', 'tmp_name' => $caminho, 'error' => UPLOAD_ERR_OK, 'size' => $tamanho ?? strlen($conteudo)];
    }

    protected function arquivo(string $nome, string $conteudo = self::PDF, ?int $tamanho = null): OpportunityFile
    {
        return new OpportunityFile($this->upload($nome, $conteudo, $tamanho));
    }

    protected function apagaNoFim(File $arquivo): File
    {
        $this->caminhosDosAnexos[] = $arquivo->getPath();

        return $arquivo;
    }

    protected function tearDown(): void
    {
        foreach (array_filter(array_unique($this->caminhosDosAnexos), file_exists(...)) as $caminho) {
            unlink($caminho);
        }

        parent::tearDown();
    }
}
