<?php

namespace Tests\AldirBlanc\Doubles;

use Curl\Curl;

/** Registra o que foi pedido à lib sem executar requisição alguma. */
class SpyCurl extends Curl
{
    public array $chamadas = [];

    public function get($url, $data = array())
    {
        $this->chamadas[] = 'GET';
    }

    public function post($url, $data = array())
    {
        $this->chamadas[] = 'POST';
    }

    public function setOpt($option, $value)
    {
        if ($option === CURLOPT_CUSTOMREQUEST) {
            $this->chamadas[] = "CUSTOMREQUEST:{$value}";
        }

        return parent::setOpt($option, $value);
    }
}
