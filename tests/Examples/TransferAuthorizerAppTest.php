<?php

declare(strict_types=1);

namespace Manza\Tests\Examples;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Manza\Client;
use Manza\Examples\TransferAuthorizer\App;
use Manza\Examples\TransferAuthorizer\Ledger;
use Manza\TransferAuthorization;
use Manza\Webhook;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Drives the example's authorizer with a signed copy of the documented
 * `payment.authorization_requested` payload (mirror of manza-ruby's
 * spec/examples/transfer_authorizer_spec.rb). A Guzzle mock handler stands
 * in for the API: no cassette, no network.
 */
final class TransferAuthorizerAppTest extends TestCase
{
    private const SECRET = 'whsec_example_authorizer_secret';
    private const BASE_URL = 'https://staging.manza.example';
    private const DRAFT_ID = '01958e51-7c5a-7000-8000-000000000012';
    private const AUTHORIZATION_ID = '01958e51-7c5a-7000-8000-000000000011';
    private const SIGNATURE_INPUT = 'manza.transfer-authorization.v1|01958e51-7c5a-7000-8000-000000000012|'
        . '9f86d081884c7d659a2feaa0c55ad015|2500.0|ZAR|01958e51-7c5a-7000-8000-000000000013|'
        . 'ext:01958e51-7c5a-7000-8000-000000000014|po_8812';

    private string $dir;
    private Ledger $ledger;
    /** @var list<string> */
    private array $log = [];
    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/manza-authorizer-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->ledger = new Ledger($this->dir . '/ledger.json');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', \GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->dir);
    }

    /**
     * getmanza/app WebhookEvents::PaymentAuthorizationRequested.example_payload, verbatim.
     *
     * @return array<string, mixed>
     */
    private static function examplePayload(): array
    {
        return [
            'event' => 'payment.authorization_requested',
            'event_id' => '01958e51-7c5a-7000-8000-000000000010',
            'created_at' => '2026-10-01T12:00:00Z',
            'data' => [
                'authorization' => [
                    'id' => '01958e51-7c5a-7000-8000-000000000011',
                    'nonce' => '9f86d081884c7d659a2feaa0c55ad015',
                    'expires_at' => '2026-10-01T13:00:00Z',
                    'signature_version' => 'manza.transfer-authorization.v1',
                ],
                'payment' => [
                    'id' => '01958e51-7c5a-7000-8000-000000000012',
                    'amount' => '2500.0',
                    'currency_code' => 'ZAR',
                    'account_id' => '01958e51-7c5a-7000-8000-000000000013',
                    'payee' => 'ext:01958e51-7c5a-7000-8000-000000000014',
                    'beneficiary_id' => '01958e51-7c5a-7000-8000-000000000015',
                    'external_account_id' => '01958e51-7c5a-7000-8000-000000000014',
                    'destination_account_id' => null,
                    'payment_reference' => 'INV-2026-001',
                    'client_reference' => 'po_8812',
                    'payee_trusted' => false,
                ],
                'signature_input' => self::SIGNATURE_INPUT,
            ],
        ];
    }

    private function recordLedgerEntry(string $amount = '2500.0'): void
    {
        $this->ledger->record(
            'po_8812',
            paymentId: '01958e51-7c5a-7000-8000-000000000012',
            amount: $amount,
            currencyCode: 'ZAR',
            accountId: '01958e51-7c5a-7000-8000-000000000013',
            payee: 'ext:01958e51-7c5a-7000-8000-000000000014',
        );
    }

    private static function expectedSignature(): string
    {
        $input = TransferAuthorization::signatureInput(
            paymentId: '01958e51-7c5a-7000-8000-000000000012',
            nonce: '9f86d081884c7d659a2feaa0c55ad015',
            amount: '2500.0',
            currencyCode: 'ZAR',
            accountId: '01958e51-7c5a-7000-8000-000000000013',
            payee: 'ext:01958e51-7c5a-7000-8000-000000000014',
            clientReference: 'po_8812',
        );

        return TransferAuthorization::sign(self::SECRET, $input);
    }

    /**
     * @param list<Psr7Response> $responses what the API answers, in order
     */
    private function app(array $responses = [], bool $allowNewPayees = true): App
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new App(
            client: new Client(apiKey: 'key-b', baseUrl: self::BASE_URL, httpClient: new GuzzleClient(['handler' => $stack])),
            secret: self::SECRET,
            ledger: $this->ledger,
            executor: static function (\Closure $job): void {
                $job();
            },
            logger: function (string $level, string $message): void {
                $this->log[] = "{$level} {$message}";
            },
            ledgerWait: 0.0,
            retryDelay: 0,
            allowNewPayees: $allowNewPayees,
        );
    }

    /**
     * Delivers $body the way Manza does, through `$_SERVER`-style headers.
     */
    private function deliver(App $app, string $body, ?string $signature = null, ?string $timestamp = null): int
    {
        $timestamp ??= (string) time();
        $signature ??= Webhook::sign($body, $timestamp, self::SECRET);

        return $app->handle($body, [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_MANZA_SIGNATURE' => $signature,
            'HTTP_X_MANZA_TIMESTAMP' => $timestamp,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function json(array $payload): string
    {
        return json_encode($payload, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function apiResponse(int $status, array $body = [], array $headers = []): Psr7Response
    {
        return new Psr7Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode((object) $body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<string> "METHOD path" per API call made
     */
    private function calls(): array
    {
        return array_map(
            static fn (array $entry): string => $entry['request']->getMethod() . ' ' . $entry['request']->getUri()->getPath(),
            $this->history,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function requestBody(int $index): array
    {
        return json_decode((string) $this->history[$index]['request']->getBody(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function logText(): string
    {
        return implode("\n", $this->log);
    }

    private function assertLogContains(string ...$needles): void
    {
        foreach ($needles as $needle) {
            $this->assertStringContainsString($needle, $this->logText());
        }
    }

    public function testSignsFromTheLedgerAndAuthorizesWithKeyB(): void
    {
        $this->recordLedgerEntry();
        $app = $this->app([self::apiResponse(200, ['id' => self::DRAFT_ID, 'status' => 'processing'])]);

        $status = $this->deliver($app, self::json(self::examplePayload()));

        $this->assertSame(200, $status);
        $this->assertSame(['POST /api/payments/' . self::DRAFT_ID . '/authorize'], $this->calls());
        $this->assertSame('Bearer key-b', $this->history[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame(
            ['authorization_id' => self::AUTHORIZATION_ID, 'signature' => self::expectedSignature()],
            $this->requestBody(0),
        );
        $this->assertLogContains('verified', 'ledger match', 'signed', 'authorized payment ' . self::DRAFT_ID . ' (status "processing")');
    }

    public function testLogsTheApiErrorTypeAndRequestIdWhenAuthorizeFails(): void
    {
        $this->recordLedgerEntry();
        $app = $this->app([self::apiResponse(
            422,
            ['error' => ['message' => 'Signature does not match', 'type' => 'invalid_signature']],
            ['X-Request-Id' => 'req_123'],
        )]);

        $status = $this->deliver($app, self::json(self::examplePayload()));

        $this->assertSame(200, $status);
        $this->assertCount(1, $this->history, 'a validation error is not retried');
        $this->assertLogContains('API error', 'invalid_signature', 'req_123');
    }

    public function testLogsBothSignatureInputsAndDoesNotAuthorizeWhenTheWebhooksDiffersFromTheLedgers(): void
    {
        $this->recordLedgerEntry();
        $payload = self::examplePayload();
        $payload['data']['signature_input'] = 'something-else';

        $this->deliver($this->app(), self::json($payload));

        $this->assertSame([], $this->calls());
        $this->assertLogContains('something-else', self::SIGNATURE_INPUT);
    }

    public function testRetriesAuthorizeAfterATransientFailure(): void
    {
        $this->recordLedgerEntry();
        $app = $this->app([self::apiResponse(503), self::apiResponse(200, ['status' => 'processing'])]);

        $this->deliver($app, self::json(self::examplePayload()));

        $this->assertCount(2, $this->history);
        $this->assertLogContains('retrying', 'authorized');
    }

    public function testGivesUpAfterThreeAttempts(): void
    {
        $this->recordLedgerEntry();
        $app = $this->app([self::apiResponse(503), self::apiResponse(503), self::apiResponse(503)]);

        $this->deliver($app, self::json(self::examplePayload()));

        $this->assertCount(3, $this->history);
        $this->assertLogContains('API error ApiException server (503)');
    }

    public function testRetriesAuthorizeWhileTheApiAnswersAuthorizationBusy(): void
    {
        $this->recordLedgerEntry();
        $app = $this->app([
            self::apiResponse(409, ['error' => ['type' => 'authorization_busy']]),
            self::apiResponse(200, ['status' => 'processing']),
        ]);

        $this->deliver($app, self::json(self::examplePayload()));

        $this->assertCount(2, $this->history);
        $this->assertLogContains('retrying', 'authorized');
    }

    public function testDoesNotRetryAnotherConflict(): void
    {
        $this->recordLedgerEntry();
        $app = $this->app([self::apiResponse(409, ['error' => ['type' => 'already_answered']])]);

        $this->deliver($app, self::json(self::examplePayload()));

        $this->assertCount(1, $this->history);
        $this->assertLogContains('API error ApiException conflict (409)');
    }

    public function testLogsThatANewPayeeWillBeTrustedOnAuthorize(): void
    {
        $this->recordLedgerEntry();
        $app = $this->app([self::apiResponse(200, ['status' => 'processing'])]);

        $this->deliver($app, self::json(self::examplePayload()));

        $this->assertLogContains('new payee ext:01958e51-7c5a-7000-8000-000000000014, will be trusted on authorize');
    }

    public function testDoesNotLogANewPayeeWhenThePayeeIsAlreadyTrusted(): void
    {
        $this->recordLedgerEntry();
        $app = $this->app([self::apiResponse(200, ['status' => 'processing'])]);
        $payload = self::examplePayload();
        $payload['data']['payment']['payee_trusted'] = true;

        $this->deliver($app, self::json($payload));

        $this->assertStringNotContainsString('new payee', $this->logText());
    }

    public function testWithNewPayeesNotAllowedDeclinesAWebhookThatDoesNotSayWhetherThePayeeIsTrusted(): void
    {
        $this->recordLedgerEntry();
        $app = $this->app([self::apiResponse(200)], allowNewPayees: false);
        $payload = self::examplePayload();
        unset($payload['data']['payment']['payee_trusted']);

        $this->deliver($app, self::json($payload));

        $this->assertSame(['POST /api/payments/' . self::DRAFT_ID . '/decline'], $this->calls());
        $this->assertSame(self::AUTHORIZATION_ID, $this->requestBody(0)['authorization_id']);
        $this->assertStringContainsString('new payee', $this->requestBody(0)['reason']);
    }

    public function testWithNewPayeesNotAllowedDeclinesATransferToAPayeeThatIsNotTrustedYet(): void
    {
        $this->recordLedgerEntry();
        $app = $this->app([self::apiResponse(200, ['id' => self::AUTHORIZATION_ID, 'status' => 'declined'])], allowNewPayees: false);

        $this->deliver($app, self::json(self::examplePayload()));

        $this->assertSame(['POST /api/payments/' . self::DRAFT_ID . '/decline'], $this->calls());
        $this->assertStringContainsString('new payee', $this->requestBody(0)['reason']);
        $this->assertLogContains('declined', 'new payee');
    }

    public function testWithNewPayeesNotAllowedAuthorizesATrustedPayee(): void
    {
        $this->recordLedgerEntry();
        $app = $this->app([self::apiResponse(200, ['status' => 'processing'])], allowNewPayees: false);
        $payload = self::examplePayload();
        $payload['data']['payment']['payee_trusted'] = true;

        $this->deliver($app, self::json($payload));

        $this->assertSame(['POST /api/payments/' . self::DRAFT_ID . '/authorize'], $this->calls());
    }

    public function testDeclinesALedgerMismatchWithAReasonNamingTheField(): void
    {
        $this->recordLedgerEntry(amount: '25000.0');
        $app = $this->app([self::apiResponse(200, ['id' => self::AUTHORIZATION_ID, 'status' => 'declined'])]);

        $status = $this->deliver($app, self::json(self::examplePayload()));

        $this->assertSame(200, $status);
        $this->assertSame(['POST /api/payments/' . self::DRAFT_ID . '/decline'], $this->calls());
        $this->assertSame(self::AUTHORIZATION_ID, $this->requestBody(0)['authorization_id']);
        $this->assertStringContainsString('amount', $this->requestBody(0)['reason']);
        $this->assertLogContains('declined');
    }

    public function testDeclinesAClientReferenceThisAuthorizerNeverIssued(): void
    {
        $app = $this->app([self::apiResponse(200)]);

        $this->deliver($app, self::json(self::examplePayload()));

        $this->assertSame(['POST /api/payments/' . self::DRAFT_ID . '/decline'], $this->calls());
        $this->assertStringContainsString('po_8812', $this->requestBody(0)['reason']);
    }

    public function testABadSignatureIsAnswered401AndCallsNothing(): void
    {
        $status = $this->deliver($this->app(), self::json(self::examplePayload()), signature: str_repeat('0', 64));

        $this->assertSame(401, $status);
        $this->assertSame([], $this->calls());
        $this->assertLogContains('rejected a delivery: invalid webhook signature');
    }

    public function testAStaleDeliveryIsAnswered401AndCallsNothing(): void
    {
        $status = $this->deliver($this->app(), self::json(self::examplePayload()), timestamp: (string) (time() - 301));

        $this->assertSame(401, $status);
        $this->assertSame([], $this->calls());
    }

    public function testASignedBodyThatIsNotAJsonObjectIsAnswered401AndCallsNothing(): void
    {
        $status = $this->deliver($this->app(), '[]');

        $this->assertSame(401, $status);
        $this->assertSame([], $this->calls());
    }

    public function testAnotherEventIsAnswered200AndCallsNothing(): void
    {
        $status = $this->deliver(
            $this->app(),
            self::json(['event' => 'test.ping', 'event_id' => '0199a1b2-0000-7000-8000-000000000010']),
        );

        $this->assertSame(200, $status);
        $this->assertSame([], $this->calls());
        $this->assertLogContains('ignoring test.ping');
    }

    public function testHandsTheAnswerToTheExecutorSoTheResponseCanGoFirst(): void
    {
        $this->recordLedgerEntry();
        $jobs = [];
        $stack = HandlerStack::create(new MockHandler([self::apiResponse(200, ['status' => 'processing'])]));
        $stack->push(Middleware::history($this->history));
        $app = new App(
            client: new Client(apiKey: 'key-b', baseUrl: self::BASE_URL, httpClient: new GuzzleClient(['handler' => $stack])),
            secret: self::SECRET,
            ledger: $this->ledger,
            executor: static function (\Closure $job) use (&$jobs): void {
                $jobs[] = $job;
            },
            logger: static function (): void {
            },
            ledgerWait: 0.0,
        );

        $status = $this->deliver($app, self::json(self::examplePayload()));

        $this->assertSame(200, $status);
        $this->assertSame([], $this->calls(), 'nothing is sent before the response');
        $this->assertCount(1, $jobs);

        $jobs[0]();
        $this->assertSame(['POST /api/payments/' . self::DRAFT_ID . '/authorize'], $this->calls());
    }
}
