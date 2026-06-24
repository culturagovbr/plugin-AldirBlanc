<?php

// Habilita o plugin AldirBlanc na suíte de testes do repositório principal (tests/docker-compose.yml),
// sem precisar editar tests/config.d/ do core — montado via tests/docker-compose.override.yml deste plugin.
// "zz-aldirblanc.d" ordena depois de "config.d" no glob de src/conf/config.php, então o array 'plugins'
// abaixo substitui por completo o do core (array_merge não funde arrays aninhados) — por isso repete a
// lista base e só adiciona "AldirBlanc" ao final.
return [
    'plugins' => [
        'MultipleLocalAuth',
        'AdminLoginAsUser',
        'RecreatePCacheOnLogin',
        'SpamDetector',
        'AldirBlanc',
    ]
];
