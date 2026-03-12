<?php

// Formato novo da API: objeto com dados do usuário/agente e entes_federados.
// Em modo development este fixture é retornado por GestorClient::get().
// Todas as informações são fictícias e não correspondem a um usuário real.
return [
    'id' => 220,
    'email' => 'usuario.ficticio@example.com.br',
    'rg' => 476439401,
    'data_atualizacao' => '2025-12-02T19:54:42.213471+00:00',
    'celular' => "88123456789",
    'logradouro' => null,
    'complemento' => "Exemplo de complemento",
    'municipio' => null,
    'nome' => 'Nome do Usuário Fictício',
    'data_nascimento' => null,
    'cpf' => '16217309050',
    'data_insercao' => '2025-12-02T19:54:42.213471+00:00',
    'excluido' => false,
    'cep' => "72550-048",
    'numero' => "10",
    'bairro' => null,
    'uf' => null,
    'entes_federados' => [
        [
            'name' => 'Primeiro Ente Federado',
            'document' => '12345678901234'
        ],
        [
            'name' => 'Segundo Ente Federado',
            'document' => '12345678901235'
        ],
        [
            'name' => 'Terceiro Ente Federado',
            'document' => '12345678901236'
        ]
    ]
];
