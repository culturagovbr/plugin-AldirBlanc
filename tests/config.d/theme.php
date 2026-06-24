<?php

// Tema Pnab: necessário para os hooks do Theme.php usados pelo plugin AldirBlanc
// (auth.successful, blockAccessOnError, entity(Opportunity).insert/update:finish).
// Plugin + tema são sempre usados juntos nesta suíte — ver docker-compose.yml deste diretório.
return [
    'themes.active' => 'Pnab',
];
