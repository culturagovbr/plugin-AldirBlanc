<?php

use AldirBlanc\Integration\ParActionPageLimits;

// Envelope paginado do catálogo, igual nas duas APIs; quem inclui declara $actions.
$skip = $this->skip ?? ParActionPageLimits::DEFAULT_SKIP;
$limit = $this->limit ?? ParActionPageLimits::DEFAULT_LIMIT;
$total = count($actions);

return [
    'pagination' => [
        'skip' => $skip,
        'limit' => $limit,
        'total' => $total,
        'next' => ($skip + $limit) < $total ? $skip + $limit : null,
        'previous' => $skip > 0 ? max(0, $skip - $limit) : null,
    ],
    'data' => array_slice($actions, $skip, $limit),
];
