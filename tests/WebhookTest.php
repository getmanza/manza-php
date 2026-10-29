<?php

declare(strict_types=1);

namespace Manza\Tests;

use Manza\Webhook;
use PHPUnit\Framework\TestCase;

/**
 * Fixed test vector, shared by every SDK in the family (mirror of
 * manza-ruby's spec/manza/webhook_spec.rb). Each SDK's verifier must
 * accept exactly this delivery. The signature was computed
 * independently with:
 *
 *     printf '%s' '1767225600.<payload>' | openssl dgst -sha256 -hmac 'whsec_test_vector_secret'
 */
final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test_vector_secret';
    private const TIMESTAMP = '1767225600';
    private const PAYLOAD = '{"event":"test.ping","event_id":"0199a1b2-0000-7000-8000-000000000010"}';
    private const SIGNATURE = '970fdd13680015f73dbd172884498c6fd1e07d5b5c41aae081272598607fea75';
    private const NOW = 1767225600;

    public function testSignsTheSharedVector(): void
    {
        $this->assertSame(self::SIGNATURE, Webhook::sign(self::PAYLOAD, self::TIMESTAMP, self::SECRET));
    }

    public function testSignAcceptsAnIntegerTimestamp(): void
    {
        $this->assertSame(self::SIGNATURE, Webhook::sign(self::PAYLOAD, 1767225600, self::SECRET));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function verify(array $overrides = []): bool
    {
        $args = array_merge([
            'payload' => self::PAYLOAD,
            'signature' => self::SIGNATURE,
            'timestamp' => self::TIMESTAMP,
            'secret' => self::SECRET,
            'now' => self::NOW,
        ], $overrides);

        return Webhook::verify(...$args);
    }

    public function testVerifyAcceptsTheSharedVector(): void
    {
        $this->assertTrue($this->verify());
    }

    public function testVerifyAcceptsAnIntegerTimestamp(): void
    {
        $this->assertTrue($this->verify(['timestamp' => 1767225600]));
    }

    public function testVerifyRejectsAWrongSecret(): void
    {
        $this->assertFalse($this->verify(['secret' => 'whsec_other']));
    }

    public function testVerifyRejectsATamperedPayload(): void
    {
        $this->assertFalse($this->verify(['payload' => str_replace('ping', 'pong', self::PAYLOAD)]));
    }

    public function testVerifyRejectsASignatureOfTheWrongLength(): void
    {
        $this->assertFalse($this->verify(['signature' => 'abc']));
    }

    public function testVerifyRejectsATimestamp301SecondsOld(): void
    {
        $this->assertFalse($this->verify(['now' => self::NOW + 301]));
    }

    public function testVerifyRejectsATimestamp301SecondsInTheFuture(): void
    {
        $this->assertFalse($this->verify(['now' => self::NOW - 301]));
    }

    public function testVerifyAcceptsATimestampExactlyAtTheTolerance(): void
    {
        $this->assertTrue($this->verify(['now' => self::NOW + 300]));
        $this->assertTrue($this->verify(['now' => self::NOW - 300]));
    }

    public function testVerifySkipsTheTimestampCheckWithANullTolerance(): void
    {
        $this->assertTrue($this->verify(['now' => self::NOW + 86400, 'tolerance' => null]));
    }

    public function testVerifyDefaultsNowToTheCurrentTime(): void
    {
        $timestamp = (string) time();
        $signature = Webhook::sign(self::PAYLOAD, $timestamp, self::SECRET);

        $this->assertTrue(Webhook::verify(self::PAYLOAD, $signature, $timestamp, self::SECRET));
        $this->assertFalse(Webhook::verify(self::PAYLOAD, self::SIGNATURE, self::TIMESTAMP, self::SECRET));
    }

    public function testVerifyRejectsANonNumericTimestamp(): void
    {
        foreach (['yesterday', '', '12.5', '0x10'] as $timestamp) {
            $signature = Webhook::sign(self::PAYLOAD, $timestamp, self::SECRET);

            $this->assertFalse(
                $this->verify(['timestamp' => $timestamp, 'signature' => $signature, 'tolerance' => null]),
                "timestamp '{$timestamp}' must be refused",
            );
        }
    }

    public function testVerifyRejectsNullInputs(): void
    {
        $this->assertFalse($this->verify(['signature' => null]));
        $this->assertFalse($this->verify(['timestamp' => null]));
        $this->assertFalse($this->verify(['payload' => null]));
        $this->assertFalse($this->verify(['secret' => null]));
    }

    public function testVerifyRejectsAnEmptySecretEvenWithASignatureMadeUnderIt(): void
    {
        $forged = Webhook::sign(self::PAYLOAD, self::TIMESTAMP, '');

        $this->assertFalse($this->verify(['secret' => '', 'signature' => $forged]));
    }

    /**
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function construct(array $headers, array $overrides = []): array
    {
        $args = array_merge([
            'payload' => self::PAYLOAD,
            'headers' => $headers,
            'secret' => self::SECRET,
            'now' => self::NOW,
        ], $overrides);

        return Webhook::constructEvent(...$args);
    }

    private function assertConstructFails(string $message, callable $construct): void
    {
        try {
            $construct();
            $this->fail("expected InvalidArgumentException '{$message}'");
        } catch (\InvalidArgumentException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    public function testConstructEventReturnsTheParsedEventForXManzaHeaders(): void
    {
        $event = $this->construct(['X-Manza-Signature' => self::SIGNATURE, 'X-Manza-Timestamp' => self::TIMESTAMP]);

        $this->assertSame(['event' => 'test.ping', 'event_id' => '0199a1b2-0000-7000-8000-000000000010'], $event);
    }

    public function testConstructEventReadsMixedCaseHeaderNames(): void
    {
        $event = $this->construct(['x-MANZA-signature' => self::SIGNATURE, 'X-manza-Timestamp' => self::TIMESTAMP]);

        $this->assertSame('test.ping', $event['event']);
    }

    public function testConstructEventReadsTheServerArrayForm(): void
    {
        $event = $this->construct(['HTTP_X_MANZA_SIGNATURE' => self::SIGNATURE, 'HTTP_X_MANZA_TIMESTAMP' => self::TIMESTAMP]);

        $this->assertSame('test.ping', $event['event']);
    }

    public function testConstructEventReadsListValuedHeaders(): void
    {
        // PSR-7 getHeaders() and Symfony's HeaderBag::all() give lists.
        $event = $this->construct(['X-Manza-Signature' => [self::SIGNATURE], 'x-manza-timestamp' => [self::TIMESTAMP]]);

        $this->assertSame('test.ping', $event['event']);
    }

    public function testConstructEventFallsBackToXZazuHeaders(): void
    {
        $event = $this->construct(['X-Zazu-Signature' => self::SIGNATURE, 'X-Zazu-Timestamp' => self::TIMESTAMP]);

        $this->assertSame('test.ping', $event['event']);
    }

    public function testConstructEventPrefersXManzaOverXZazu(): void
    {
        $event = $this->construct([
            'X-Manza-Signature' => self::SIGNATURE,
            'X-Manza-Timestamp' => self::TIMESTAMP,
            'X-Zazu-Signature' => str_repeat('0', 64),
            'X-Zazu-Timestamp' => '1',
        ]);

        $this->assertSame('test.ping', $event['event']);
    }

    public function testConstructEventRaisesOnAMissingSignatureHeader(): void
    {
        $this->assertConstructFails(
            'missing X-Manza-Signature header',
            fn () => $this->construct(['X-Manza-Timestamp' => self::TIMESTAMP]),
        );
    }

    public function testConstructEventRaisesOnAMissingTimestampHeader(): void
    {
        $this->assertConstructFails(
            'missing X-Manza-Timestamp header',
            fn () => $this->construct(['X-Manza-Signature' => self::SIGNATURE]),
        );
    }

    public function testConstructEventRaisesOnABadSignature(): void
    {
        $this->assertConstructFails(
            'invalid webhook signature',
            fn () => $this->construct(['X-Manza-Signature' => str_repeat('0', 64), 'X-Manza-Timestamp' => self::TIMESTAMP]),
        );
    }

    public function testConstructEventRaisesOnAStaleTimestamp(): void
    {
        $this->assertConstructFails(
            'webhook timestamp outside tolerance',
            fn () => $this->construct(
                ['X-Manza-Signature' => self::SIGNATURE, 'X-Manza-Timestamp' => self::TIMESTAMP],
                ['now' => self::NOW + 301],
            ),
        );
    }

    public function testConstructEventAcceptsAStaleTimestampWithANullTolerance(): void
    {
        $event = $this->construct(
            ['X-Manza-Signature' => self::SIGNATURE, 'X-Manza-Timestamp' => self::TIMESTAMP],
            ['now' => self::NOW + 301, 'tolerance' => null],
        );

        $this->assertSame('test.ping', $event['event']);
    }

    public function testConstructEventRaisesOnABodyThatIsNotJson(): void
    {
        $body = 'not json';
        $headers = ['X-Manza-Signature' => Webhook::sign($body, self::TIMESTAMP, self::SECRET), 'X-Manza-Timestamp' => self::TIMESTAMP];

        $this->assertConstructFails('webhook payload is not JSON', fn () => $this->construct($headers, ['payload' => $body]));
    }

    public function testConstructEventRaisesOnAnEmptySecret(): void
    {
        $headers = ['X-Manza-Signature' => Webhook::sign(self::PAYLOAD, self::TIMESTAMP, ''), 'X-Manza-Timestamp' => self::TIMESTAMP];

        $this->assertConstructFails('invalid webhook signature', fn () => $this->construct($headers, ['secret' => '']));
    }

    public function testConstructEventRaisesOnABodyThatIsValidJsonButNotAnObject(): void
    {
        foreach (['[]', 'null', '42', '"text"'] as $body) {
            $headers = ['X-Manza-Signature' => Webhook::sign($body, self::TIMESTAMP, self::SECRET), 'X-Manza-Timestamp' => self::TIMESTAMP];

            $this->assertConstructFails(
                'webhook payload is not a JSON object',
                fn () => $this->construct($headers, ['payload' => $body]),
            );
        }
    }

    public function testConstructEventAcceptsAnEmptyJsonObject(): void
    {
        $headers = ['X-Manza-Signature' => Webhook::sign('{}', self::TIMESTAMP, self::SECRET), 'X-Manza-Timestamp' => self::TIMESTAMP];

        $this->assertSame([], $this->construct($headers, ['payload' => '{}']));
    }
}
