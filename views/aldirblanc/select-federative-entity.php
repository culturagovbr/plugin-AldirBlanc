<?php

/**
 * @var \MapasCulturais\Themes\BaseV2\Theme $this
 * @var \MapasCulturais\App $app
 */

use MapasCulturais\i;

$this->import('
    federative-entity-selector
');
?>

<div class="main-app choose-federative-entity-page">
    <div class="choose-federative-entity-page__container">
        <h1 class="choose-federative-entity-page__title">
            <?php i::_e('Selecione o Ente Federado') ?>
        </h1>
        <p class="choose-federative-entity-page__description">
            <?php i::_e('Para continuar, é necessário selecionar o ente federado que deseja utilizar.') ?>
        </p>
        <federative-entity-selector></federative-entity-selector>
    </div>
</div>