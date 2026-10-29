<?php

declare(strict_types=1);

namespace Manza\Tests\Examples;

use Manza\Examples\TransferAuthorizer\Env;
use PHPUnit\Framework\TestCase;

/**
 * ALLOW_NEW_PAYEES parsing, as manza-ruby's config.ru reads it:
 * `!%w[false 0 no].include?(ENV.fetch("ALLOW_NEW_PAYEES", "true").strip.downcase)`.
 */
final class EnvTest extends TestCase
{
    private const NAME = 'MANZA_EXAMPLE_TEST_FLAG';

    protected function tearDown(): void
    {
        putenv(self::NAME);
    }

    public function testIsOnWhenUnset(): void
    {
        putenv(self::NAME);

        $this->assertTrue(Env::enabled(self::NAME));
    }

    public function testIsOffForFalseZeroAndNoInAnyCaseOrPadding(): void
    {
        foreach (['false', '0', 'no', ' FALSE ', 'No'] as $value) {
            putenv(self::NAME . "={$value}");

            $this->assertFalse(Env::enabled(self::NAME), "'{$value}' must turn it off");
        }
    }

    public function testIsOnForAnythingElse(): void
    {
        foreach (['true', '1', 'yes', ''] as $value) {
            putenv(self::NAME . "={$value}");

            $this->assertTrue(Env::enabled(self::NAME), "'{$value}' must leave it on");
        }
    }
}
