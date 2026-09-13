<?php

// Derivada da fixture da Gestão: os dois contratos declaram o mesmo schema de resposta e os
// mesmos parâmetros. O retorno real da Conecta foi medido em 926 itens com 28 nomes distintos.

// A fixture da Gestão lê $this->skip e $this->limit do client que a incluiu.
return require __DIR__ . '/../gestao/par-acoes.php';
