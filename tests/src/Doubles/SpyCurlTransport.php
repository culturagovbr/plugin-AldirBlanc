<?php

namespace Tests\AldirBlanc\Doubles;

use AldirBlanc\Http\Transport\CurlTransport;
use Curl\Curl;

/** Expõe os handles que o transporte criou, para provar que não há reuso entre chamadas. */
class SpyCurlTransport extends CurlTransport
{
    /** @var list<SpyCurl> */
    public array $handles = [];

    protected function novoHandle(): Curl
    {
        $handle = new SpyCurl();
        $this->handles[] = $handle;

        return $handle;
    }
}
