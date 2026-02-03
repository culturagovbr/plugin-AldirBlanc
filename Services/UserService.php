<?php

namespace AldirBlanc\Services;

use MapasCulturais\App;

class UserService
{
    public function getCpf(): string
    {
        $app = App::i();
        $cpfField = $app->auth->getMetadataFieldCpfFromConfig();
        return $this->documentOnlyNumbers($app->user->profile->getMetadata($cpfField));
    }

    private function documentOnlyNumbers(string $document): string
    {
        return preg_replace('/[^0-9]/', '', $document);
    }
}
