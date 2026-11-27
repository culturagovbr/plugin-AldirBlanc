<?php

namespace AldirBlanc\Http\Transport;

/** Corpo de um envio e o tipo que o descreve. */
final readonly class RequestBody
{
    public const JSON = 'application/json';

    private function __construct(public string $content, public string $contentType)
    {
    }

    public static function json(array $dados): self
    {
        return new self((string) json_encode($dados, JSON_UNESCAPED_UNICODE), self::JSON);
    }

    /**
     * Montado em bytes: o curl da lib transforma array em query string e não repete campo.
     * @param list<array{campo: string, conteudo: string, arquivo?: string, tipo?: string}> $partes na ordem em que vão
     */
    public static function multipart(array $partes): self
    {
        $boundary = bin2hex(random_bytes(16));
        $corpo = implode('', array_map(fn(array $parte) => "--{$boundary}\r\n" . self::parte($parte) . "\r\n", $partes));

        return new self("{$corpo}--{$boundary}--\r\n", "multipart/form-data; boundary={$boundary}");
    }

    private static function parte(array $parte): string
    {
        $disposicao = 'Content-Disposition: form-data; name="' . self::escapa($parte['campo']) . '"';
        $arquivo = isset($parte['arquivo']) ? '; filename="' . self::escapa($parte['arquivo']) . "\"\r\nContent-Type: {$parte['tipo']}" : '';

        return "{$disposicao}{$arquivo}\r\n\r\n{$parte['conteudo']}";
    }

    private static function escapa(string $valor): string
    {
        return strtr($valor, ["\r" => '%0D', "\n" => '%0A', '"' => '%22']);
    }
}
