<?php

namespace Tests\AldirBlanc;

use MapasCulturais\App;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Abstract\TestCase;
use Tests\AldirBlanc\Traits\AssertsHooks;

/**
 * Valida o helper assertHookFired()/assertHookNotFired() (src/Traits/AssertsHooks.php)
 * com um caso positivo e um caso negativo para cada método — prova que o helper
 * realmente detecta disparo/não-disparo, e não está sempre passando por acidente.
 */
class AssertsHooksTest extends TestCase
{
    use AssertsHooks;

    private const TEST_HOOK = 'aldirblanc.tests.smoke-hook';

    // ===== assertHookFired =====

    function testAssertHookFiredPassesWhenHookIsActuallyFired()
    {
        $this->assertHookFired(self::TEST_HOOK, function () {
            App::i()->applyHook(self::TEST_HOOK);
        });
    }

    function testAssertHookFiredFailsWhenHookIsNotFired()
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertHookFired(self::TEST_HOOK, function () {
            // ação que não dispara o hook
        });
    }

    // ===== assertHookNotFired =====

    function testAssertHookNotFiredPassesWhenHookIsNotFired()
    {
        $this->assertHookNotFired(self::TEST_HOOK, function () {
            // ação que não dispara o hook
        });
    }

    function testAssertHookNotFiredFailsWhenHookIsActuallyFired()
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertHookNotFired(self::TEST_HOOK, function () {
            App::i()->applyHook(self::TEST_HOOK);
        });
    }
}
