<?php

namespace AldirBlanc\Enum;

/**
 * Valores de tipos_proponentes aceitos pela API Oportunidade Cult.
 * Mapeamento das labels do Mapas (registrationProponentTypes) para os valores do enum.
 */
enum TipoProponenteEnum: string
{
    case PESSOA_FISICA = 'pessoa_fisica';
    case MEI = 'mei_microempreendedor_individual';
    case COLETIVO = 'coletivos_e_grupos_informais_sem_cnpj';

    /**
     * Pessoa Jurídica sem correspondência exata no enum da API; enviar este valor.
     */
    public const PESSOA_JURIDICA_GENERICA = 'pessoa_juridica';

    /**
     * Mapeamento label (Mapas) → valor da API.
     * Inclui as opções padrão do registration.proponentTypes e possíveis variações.
     *
     * @return array<string, string>
     */
    public static function labelToApiValueMap(): array
    {
        return [
            'Pessoa Física' => self::PESSOA_FISICA->value,
            'MEI' => self::MEI->value,
            'Coletivo' => self::COLETIVO->value,
            'Pessoa Jurídica' => self::PESSOA_JURIDICA_GENERICA,
        ];
    }

    /**
     * Converte uma label do Mapas para o valor aceito pela API.
     * Retorna null se a label for desconhecida.
     */
    public static function fromLabel(string $label): ?string
    {
        $map = self::labelToApiValueMap();
        $trimmed = trim($label);
        return $map[$trimmed] ?? null;
    }
}
