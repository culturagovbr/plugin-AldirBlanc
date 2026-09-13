<?php

use AldirBlanc\Http\Clients\ParAcaoClient;

$skip = $this->skip ?? ParAcaoClient::DEFAULT_SKIP;
$limit = $this->limit ?? ParAcaoClient::DEFAULT_LIMIT;

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
        'id_par_acao_meta_acao' => 2,
        'id_par_cadastro' => 3,
        'id_par_tipo_acao' => 6,
        'id_par_plano_acao_meta' => 1,
        'nome_acao' => '1.6 Programa Nacional Aldir Blanc de Requalificação de Infraestrutura Cultural - Programa INFRACultura',
        'valor_acao' => '7378.88',
        'data_insercao' => '2026-05-06T10:26:26.274950-03:00',
        'data_atualizacao' => '2026-05-06T10:26:26.274950-03:00',
        'excluido' => false,
    ],
];

$total = count($actions);
$page = array_slice($actions, $skip, $limit);
$next = ($skip + $limit) < $total ? $skip + $limit : null;
$previous = $skip > 0 ? max(0, $skip - $limit) : null;

return [
    'pagination' => [
        'skip' => $skip,
        'limit' => $limit,
        'total' => $total,
        'next' => $next,
        'previous' => $previous,
    ],
    'data' => $page,
];
