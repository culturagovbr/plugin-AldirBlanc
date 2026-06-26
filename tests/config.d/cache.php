<?php

use Symfony\Component\Cache\Adapter\ArrayAdapter;

// O core (tests/config.d/cache.php) usa NullAdapter para app.cache, o que impede o
// mecanismo de contagem de tentativas do OportunidadeCultJob de funcionar nos testes:
// cada fetch() retorna false e cada save() é descartado silenciosamente.
// ArrayAdapter mantém os valores na memória do processo — suficiente para que o contador
// de tentativas persista entre as iterações de um mesmo processJobs().
return [
    'app.cache' => new ArrayAdapter(),
];
