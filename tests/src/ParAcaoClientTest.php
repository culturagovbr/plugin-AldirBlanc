<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Http\Clients\ParAcaoClient;
use AldirBlanc\Plugin;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Doubles\FakeTransport;

/**
 * O que o client do catálogo pede à API: a guarda de paginação existe para o front não
 * conseguir pedir página arbitrária, e é aplicada em silêncio.
 */
class ParAcaoClientTest extends TestCase
{
    private const HOST = 'http://cultbr.invalid';

    private function urlPedida(int $skip, int $limit): string
    {
        $plugin = Plugin::getInstance();
        $ref = new \ReflectionProperty($plugin, '_config');
        $ref->setAccessible(true);

        $config = $ref->getValue($plugin);
        $original = $config['client'];
        $config['client']['mode'] = 'live';
        $config['client']['host'] = self::HOST;
        $config['client']['token'] = 'token-de-teste';
        $ref->setValue($plugin, $config);

        $transport = new FakeTransport(status: 200, body: '{"data":[],"pagination":{}}');

        try {
            (new ParAcaoClient($skip, $limit, $transport))->get();
        } finally {
            $config = $ref->getValue($plugin);
            $config['client'] = $original;
            $ref->setValue($plugin, $config);
        }

        return (string) $transport->ultimaUrl();
    }

    function testPaginacaoPedidaViajaNaQueryString()
    {
        $this->assertStringContainsString('skip=0&limit=1000', $this->urlPedida(0, 1000));
    }

    /** O catálogo real cabe numa página; um limite fora da lista é trocado pelo default. */
    function testLimiteForaDaListaEhCoagidoParaODefault()
    {
        $this->assertStringContainsString(
            'limit=' . ParAcaoClient::DEFAULT_LIMIT,
            $this->urlPedida(0, 50)
        );
    }

    function testSkipNegativoNaoChegaAApi()
    {
        $this->assertStringContainsString('skip=0', $this->urlPedida(-30, 1000));
    }

    function testSkipPositivoEhPreservado()
    {
        $this->assertStringContainsString('skip=25', $this->urlPedida(25, 1000));
    }
}
