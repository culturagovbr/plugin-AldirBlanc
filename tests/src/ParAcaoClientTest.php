<?php

namespace Tests\AldirBlanc;

use AldirBlanc\Http\Clients\ParAcaoClient;
use AldirBlanc\Integration\ParActionPageLimits;
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

    /** Tamanho do catálogo na homologação, para a folga do teto ser verificável. */
    private const ACOES_MEDIDAS = 1044;

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
        $this->assertStringContainsString(
            'skip=0&limit=' . ParActionPageLimits::DEFAULT_LIMIT,
            $this->urlPedida(0, ParActionPageLimits::DEFAULT_LIMIT)
        );
    }

    /**
     * Contagem lida na homologação em 2026-09-16, pela paginação da própria tela: 1044 ações.
     * A folga é o que impede o dedupe de rodar sobre página incompleta e descartar associação.
     */
    function testTetoTemFolgaSobreOCatalogoMedido()
    {
        $this->assertGreaterThan(
            self::ACOES_MEDIDAS,
            ParActionPageLimits::DEFAULT_LIMIT,
            'O catálogo precisa caber inteiro numa página'
        );
    }

    function testPedidoAcimaDoTetoEhCoagido()
    {
        $this->assertStringContainsString(
            'limit=' . ParActionPageLimits::DEFAULT_LIMIT,
            $this->urlPedida(0, ParActionPageLimits::DEFAULT_LIMIT * 5)
        );
    }

    /** Tela servida com JS em cache pede o teto anterior, e não pode receber página cortada. */
    function testPedidoAbaixoDoTetoEhCoagido()
    {
        $this->assertStringContainsString(
            'limit=' . ParActionPageLimits::DEFAULT_LIMIT,
            $this->urlPedida(0, intdiv(ParActionPageLimits::DEFAULT_LIMIT, 2))
        );
    }

    /** O catálogo real cabe numa página; um limite fora da lista é trocado pelo default. */
    function testLimiteForaDaListaEhCoagidoParaODefault()
    {
        $this->assertStringContainsString(
            'limit=' . ParActionPageLimits::DEFAULT_LIMIT,
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
