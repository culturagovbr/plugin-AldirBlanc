<?php

// Recorte da homologação, coletado em 2026-09-18: nomes repetidos são o que exercita o dedupe.
$actions = [
    [
        'id_par_acao_meta_acao' => 1,
        'id_par_cadastro' => 3,
        'id_par_tipo_acao' => 1,
        'id_par_plano_acao_meta' => 1,
        'nome_acao' => '1.1 Fomento Cultural',
        'valor_acao' => '100000.00',
        'data_insercao' => '2026-05-06T10:26:26.274950-03:00',
        'data_atualizacao' => '2026-05-06T10:26:26.274950-03:00',
        'excluido' => false,
    ],
    [
        'id_par_acao_meta_acao' => 5,
        'id_par_cadastro' => 4,
        'id_par_tipo_acao' => 1,
        'id_par_plano_acao_meta' => 4,
        'nome_acao' => '1.1 Fomento Cultural',
        'valor_acao' => '100000.00',
        'data_insercao' => '2026-05-06T10:26:26.275989-03:00',
        'data_atualizacao' => '2026-05-06T10:26:26.275989-03:00',
        'excluido' => false,
    ],
    [
        'id_par_acao_meta_acao' => 3,
        'id_par_cadastro' => 3,
        'id_par_tipo_acao' => 12,
        'id_par_plano_acao_meta' => 2,
        'nome_acao' => '2.1 Fomento a projetos de Pontos de Cultura',
        'valor_acao' => '180000.00',
        'data_insercao' => '2026-05-06T10:26:26.274950-03:00',
        'data_atualizacao' => '2026-05-06T10:26:26.274950-03:00',
        'excluido' => false,
    ],
    [
        'id_par_acao_meta_acao' => 7,
        'id_par_cadastro' => 4,
        'id_par_tipo_acao' => 12,
        'id_par_plano_acao_meta' => 5,
        'nome_acao' => '2.1 Fomento a projetos de Pontos de Cultura',
        'valor_acao' => '180000.00',
        'data_insercao' => '2026-05-06T10:26:26.275989-03:00',
        'data_atualizacao' => '2026-05-06T10:26:26.275989-03:00',
        'excluido' => false,
    ],
];

return require __DIR__ . '/../pagina-do-par.php';
