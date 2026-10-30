<?php

declare(strict_types=1);

namespace Manza\Tests\Examples;

use Manza\Examples\TransferAuthorizer\Routing;
use PHPUnit\Framework\TestCase;

/**
 * Mirror of manza-ruby's spec/examples/routing_spec.rb.
 */
final class RoutingTest extends TestCase
{
    public function testSaysTheChallengeWentToTheAuthorizerWhenItIsPending(): void
    {
        $this->assertStringContainsString('challenge sent', Routing::describe(['status' => 'pending']));
    }

    public function testExplainsEveryCreateTimeRefusalReasonInWords(): void
    {
        $expected = [
            'untrusted_payee' => 'bank details are not trusted',
            'unrestricted_api_key' => 'key A has no IP allowlist',
            'over_new_payee_limit' => 'limit per transfer to a new payee',
            'over_transfer_limit' => 'over the limit per transfer',
            'over_daily_limit' => 'limit per 24 hours',
            'currency_mismatch' => 'not the enrolled currency',
            'suspended' => 'is suspended',
            'secret_expired' => 'secret has expired',
        ];
        $this->assertEqualsCanonicalizing(array_keys($expected), array_keys(Routing::REFUSAL_REASONS));

        foreach ($expected as $reason => $words) {
            $line = Routing::describe(['status' => 'refused', 'refusal_reason' => $reason]);

            foreach (['approvers', 'refused', $reason, $words] as $needle) {
                $this->assertStringContainsString($needle, $line);
            }
        }
    }

    public function testPointsAtTheDocsForAReasonItDoesNotKnow(): void
    {
        $line = Routing::describe(['status' => 'refused', 'refusal_reason' => 'something_new']);

        $this->assertStringContainsString('something_new', $line);
        $this->assertStringContainsString('transfer authorization docs', $line);
    }

    public function testSaysNoAuthorizerWasEnrolledWhenThereIsNoAuthorization(): void
    {
        $this->assertStringContainsString('no transfer authorizer', Routing::describe(null));
    }
}
