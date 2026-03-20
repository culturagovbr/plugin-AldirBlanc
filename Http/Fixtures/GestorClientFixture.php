<?php

// Formato novo da API: objeto com dados do usuário/agente e entes_federados.
// Em modo development este fixture é retornado por GestorClient::get().
// Todas as informações são fictícias e não correspondem a um usuário real.
return [
    'id' => 220,
    'email' => 'usuario.ficticio@example.com.br',
    'rg' => 476439401,
    'data_atualizacao' => '2025-12-02T19:54:42.213471+00:00',
    'celular' => '88123456789',
    'logradouro' => null,
    'complemento' => 'Exemplo de complemento',
    'municipio' => null,
    'nome' => 'Nome do Usuário Fictício',
    'data_nascimento' => null,
    'cpf' => '16217309050',
    'data_insercao' => '2025-12-02T19:54:42.213471+00:00',
    'excluido' => false,
    'cep' => '72550-048',
    'numero' => '10',
    'bairro' => null,
    'uf' => null,
    'entes_federados' => [
        [
            'name' => 'Primeiro Ente Federado',
            'document' => '12345678901234',
            'exercicios' => [
                [
                    'id' => 307,
                    'ano' => 2025,
                    'metas' => [
                        [
                            'id' => 919,
                            'nome' => 'Custo operacional',
                            'valor' => 1000,
                            'acoes' => [
                                [
                                    'id' => 830,
                                    'nome' => '3.2 Gestão e operacionalização',
                                    'valor' => 1000,
                                    'atividades' => [
                                        ['id' => 984, 'nome' => 'Consultor teste', 'valor' => 1000],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'id' => 920,
                            'nome' => 'Ações Gerais',
                            'valor' => 41929.96,
                            'acoes' => [
                                [
                                    'id' => 831,
                                    'nome' => '1.1 Fomento Cultural',
                                    'valor' => 21000,
                                    'atividades' => [
                                        ['id' => 985, 'nome' => 'teste', 'valor' => 10000],
                                        ['id' => 986, 'nome' => 'Teste adicionar nova atividade', 'valor' => 11000],
                                    ],
                                ],
                                [
                                    'id' => 832,
                                    'nome' => '1.2 Contratação de serviços diretos',
                                    'valor' => 20929.96,
                                    'atividades' => [
                                        ['id' => 987, 'nome' => 'teste 4', 'valor' => 20929.96],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'id' => 921,
                            'nome' => 'Política Nacional de Cultura Viva',
                            'valor' => 0,
                            'acoes' => [],
                        ],
                    ],
                ],
                [
                    'id' => 308,
                    'ano' => 2026,
                    'metas' => [
                        ['id' => 922, 'nome' => 'Custo operacional', 'valor' => 0, 'acoes' => []],
                        ['id' => 923, 'nome' => 'Ações Gerais', 'valor' => 0, 'acoes' => []],
                        ['id' => 924, 'nome' => 'Política Nacional de Cultura Viva', 'valor' => 0, 'acoes' => []],
                    ],
                ],
                [
                    'id' => 309,
                    'ano' => 2027,
                    'metas' => [
                        ['id' => 925, 'nome' => 'Custo operacional', 'valor' => 0, 'acoes' => []],
                        ['id' => 926, 'nome' => 'Ações Gerais', 'valor' => 0, 'acoes' => []],
                        ['id' => 927, 'nome' => 'Política Nacional de Cultura Viva', 'valor' => 0, 'acoes' => []],
                    ],
                ],
                [
                    'id' => 310,
                    'ano' => 2028,
                    'metas' => [
                        ['id' => 928, 'nome' => 'Custo operacional', 'valor' => 0, 'acoes' => []],
                        ['id' => 929, 'nome' => 'Ações Gerais', 'valor' => 0, 'acoes' => []],
                        ['id' => 930, 'nome' => 'Política Nacional de Cultura Viva', 'valor' => 0, 'acoes' => []],
                    ],
                ],
            ],
        ],
        [
            'name' => 'Segundo Ente Federado',
            'document' => '12345678901235',
            'exercicios' => [],
        ],
        [
            'name' => 'Terceiro Ente Federado',
            'document' => '12345678901236',
            'exercicios' => [
                [
                    'id' => 223,
                    'ano' => 2025,
                    'metas' => [
                        [
                            'id' => 667,
                            'nome' => 'Ações Gerais',
                            'valor' => 1200000.13,
                            'acoes' => [
                                [
                                    'id' => 628,
                                    'nome' => '1.1 Fomento Cultural',
                                    'valor' => 1200000.13,
                                    'atividades' => [
                                        ['id' => 757, 'nome' => 'Atividade meta 1', 'valor' => 1200000.13],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'id' => 668,
                            'nome' => 'Política Nacional de Cultura Viva',
                            'valor' => 5363558,
                            'acoes' => [
                                [
                                    'id' => 629,
                                    'nome' => '2.2 Fomento a projetos de Pontões de Cultura',
                                    'valor' => 5363558,
                                    'atividades' => [
                                        ['id' => 758, 'nome' => 'Atividade meta 2', 'valor' => 5363558],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'id' => 669,
                            'nome' => 'Custo operacional',
                            'valor' => 345450.43,
                            'acoes' => [
                                [
                                    'id' => 630,
                                    'nome' => '3.1 [Diárias e passagens] - Programa Nacional Aldir Blanc de Formação em Gestão Pública de Cultura',
                                    'valor' => 345450.43,
                                    'atividades' => [
                                        ['id' => 759, 'nome' => 'Atividade meta 3', 'valor' => 345450.43],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
