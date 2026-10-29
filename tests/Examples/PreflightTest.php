<?php

declare(strict_types=1);

namespace Manza\Tests\Examples;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Manza\Client;
use Manza\Examples\TransferAuthorizer\Preflight;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Drives the example's preflight against stubbed copies of the documented
 * responses (mirror of manza-ruby's spec/examples/preflight_spec.rb). No
 * cassette, no network: each failure is a stubbed response.
 */
final class PreflightTest extends TestCase
{
    private const BASE_URL = 'https://staging.manza.example';
    private const ACCOUNT_ID = '01958e51-7c5a-7000-8000-000000000013';
    private const BENEFICIARY_ID = '01958e51-7c5a-7000-8000-000000000015';
    private const ENDPOINT_ID = '01958e51-7c5a-7000-8000-000000000020';
    private const NOW = 1792497600; // 2026-10-20T12:00:00Z

    /** @var array<string, string> */
    private array $env;
    /** @var array<string, mixed> */
    private array $authorizer;
    /** @var array<string, mixed> */
    private array $endpoint;
    /** @var array<string, mixed> */
    private array $payee;
    /** @var array<string, Psr7Response> "METHOD path" => a response that replaces the default */
    private array $overrides = [];
    /** @var list<string> "<api key> METHOD path" per request made */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->env = [
            'MANZA_BASE_URL' => self::BASE_URL,
            'MANZA_CREATOR_API_KEY' => 'key-a',
            'MANZA_AUTHORIZER_API_KEY' => 'key-b',
            'MANZA_AUTHORIZER_SECRET' => 'whsec_example',
            'MANZA_ACCOUNT_ID' => self::ACCOUNT_ID,
            'MANZA_BENEFICIARY_ID' => self::BENEFICIARY_ID,
        ];
        $this->authorizer = [
            'status' => 'active', 'suspension_reason' => null, 'currency_code' => 'MAD', 'per_transfer_limit' => '1000.0',
            'daily_limit' => '5000.0', 'daily_authorized_total' => '100.0', 'secret_expires_at' => '2027-01-18T00:00:00Z',
            'webhook_endpoint_id' => self::ENDPOINT_ID, 'enrolled_at' => '2026-10-01T08:30:00Z',
        ];
        $this->endpoint = ['id' => self::ENDPOINT_ID, 'url' => 'https://authorizer.example.com/', 'status' => 'active'];
        $this->payee = ['id' => '01958e51-7c5a-7000-8000-000000000014', 'default' => true, 'trusted' => true];
    }

    /**
     * @param array<string, mixed>|list<mixed> $body
     */
    private static function json(int $status, array $body): Psr7Response
    {
        return new Psr7Response($status, ['Content-Type' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private static function error(int $status, string $type, string $message = 'refused'): Psr7Response
    {
        return self::json($status, ['error' => ['type' => $type, 'message' => $message]]);
    }

    /**
     * The documented responses, each readable only with the key the
     * preflight should use for it (key A, or key B for the authorizer).
     */
    private function respond(RequestInterface $request): Psr7Response
    {
        $key = substr($request->getHeaderLine('Authorization'), \strlen('Bearer '));
        $route = $request->getMethod() . ' ' . $request->getUri()->getPath();
        $this->requests[] = "{$key} {$route}";

        if (isset($this->overrides[$route])) {
            return $this->overrides[$route];
        }

        $routes = [
            'GET /api/entity' => ['key-a', fn () => ['id' => 'e1', 'name' => 'Acme']],
            'GET /api/transfer_authorizer' => ['key-b', fn () => $this->authorizer],
            'GET /api/accounts/' . self::ACCOUNT_ID => ['key-a', fn () => ['id' => self::ACCOUNT_ID, 'currency_code' => 'MAD']],
            'GET /api/webhook_endpoints/' . self::ENDPOINT_ID => ['key-a', fn () => $this->endpoint],
            'GET /api/beneficiaries/' . self::BENEFICIARY_ID => ['key-a', fn () => ['id' => self::BENEFICIARY_ID, 'external_accounts' => [$this->payee]]],
        ];
        if (!isset($routes[$route]) || $routes[$route][0] !== $key) {
            return self::error(404, 'not_found_error', "no stub for {$key} {$route}");
        }

        return self::json(200, $routes[$route][1]());
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function runPreflight(string $amount = '10.00'): array
    {
        $out = fopen('php://memory', 'w+');
        $handler = fn (RequestInterface $request, array $options): PromiseInterface => Create::promiseFor($this->respond($request));
        $preflight = new Preflight(
            env: $this->env,
            rawAmount: $amount,
            out: $out,
            now: self::NOW,
            clientFactory: static fn (string $apiKey, string $baseUrl): Client => new Client(
                apiKey: $apiKey,
                baseUrl: $baseUrl,
                httpClient: new GuzzleClient(['handler' => HandlerStack::create($handler)]),
            ),
        );

        $ok = $preflight->run();
        rewind($out);

        return [$ok, (string) stream_get_contents($out)];
    }

    /**
     * @return list<string>
     */
    private static function lines(string $output, string $marker): array
    {
        return array_values(array_filter(
            explode("\n", $output),
            static fn (string $line): bool => str_starts_with($line, $marker),
        ));
    }

    /**
     * @return list<string>
     */
    private static function failures(string $output): array
    {
        return self::lines($output, '✗');
    }

    private function assertFirstFailureContains(string $output, string ...$needles): void
    {
        $first = self::failures($output)[0] ?? '';
        foreach ($needles as $needle) {
            $this->assertStringContainsString($needle, $first);
        }
    }

    public function testPassesEveryStepWhenTheSetupIsComplete(): void
    {
        [$ok, $output] = $this->runPreflight();

        $this->assertTrue($ok);
        $this->assertSame([], self::failures($output));
        $this->assertCount(9, self::lines($output, '✓'));
    }

    public function testFailsWhenAKeyIsMissing(): void
    {
        $this->env['MANZA_AUTHORIZER_API_KEY'] = '';
        [$ok, $output] = $this->runPreflight();

        $this->assertFalse($ok);
        $this->assertFirstFailureContains($output, 'MANZA_AUTHORIZER_API_KEY');
        $this->assertSame([], $this->requests);
    }

    public function testFailsWhenTheAccountOrBeneficiaryIdIsMissing(): void
    {
        $this->env['MANZA_BENEFICIARY_ID'] = '';
        [$ok, $output] = $this->runPreflight();

        $this->assertFalse($ok);
        $this->assertFirstFailureContains($output, 'MANZA_BENEFICIARY_ID');
        $this->assertSame([], $this->requests);
    }

    public function testFailsWhenTheAmountIsNotADecimalAndRunsTheOtherChecks(): void
    {
        [$ok, $output] = $this->runPreflight('ten');

        $this->assertFalse($ok);
        $this->assertCount(1, self::failures($output));
        $this->assertFirstFailureContains($output, '"ten"', '10.00');
        $this->assertStringContainsString('✓ transfer authorizer is active', $output);
        $this->assertStringNotContainsString('limit per transfer and', $output);
    }

    public function testFailsWhenTheAmountIsNotPositive(): void
    {
        foreach (['-5', '0', '1/2', '1e2'] as $amount) {
            [, $output] = $this->runPreflight($amount);

            $this->assertFirstFailureContains($output, "\"{$amount}\"", 'positive');
        }
    }

    public function testStopsBeforeAnyRequestOnAMissingKeyEvenWithABadAmount(): void
    {
        $this->env['MANZA_CREATOR_API_KEY'] = '';
        [, $output] = $this->runPreflight('0');

        $this->assertCount(2, self::failures($output));
        $this->assertSame([], $this->requests);
    }

    public function testFailsWhenBothKeysAreTheSame(): void
    {
        $this->env['MANZA_AUTHORIZER_API_KEY'] = 'key-a';
        [, $output] = $this->runPreflight();

        $this->assertFirstFailureContains($output, 'different');
    }

    public function testFailsWhenKeyACannotReachTheEntity(): void
    {
        $this->overrides['GET /api/entity'] = self::error(401, 'authentication_error');
        [, $output] = $this->runPreflight();

        $this->assertFirstFailureContains($output, 'key A', 'MANZA_CREATOR_API_KEY');
    }

    public function testFailsWhenNoAuthorizerIsEnrolled(): void
    {
        $this->overrides['GET /api/transfer_authorizer'] = self::error(404, 'not_found_error');
        [, $output] = $this->runPreflight();

        $this->assertFirstFailureContains($output, 'no transfer authorizer is enrolled', 'Transfer approval');
    }

    public function testFailsWhenKeyBCannotReadTheAuthorizer(): void
    {
        $this->overrides['GET /api/transfer_authorizer'] = self::error(403, 'insufficient_scope');
        [, $output] = $this->runPreflight();

        $this->assertFirstFailureContains($output, 'key B', 'insufficient_scope', 'transfers:authorize');
    }

    public function testFailsWhenKeyACannotReadTheAccount(): void
    {
        $this->overrides['GET /api/accounts/' . self::ACCOUNT_ID] = self::error(403, 'insufficient_scope');
        [, $output] = $this->runPreflight();

        $this->assertFirstFailureContains($output, 'key A', 'accounts:read');
    }

    public function testFailsWhenKeyACannotReadTheEnrolledWebhookEndpoint(): void
    {
        $this->overrides['GET /api/webhook_endpoints/' . self::ENDPOINT_ID] = self::error(403, 'insufficient_scope');
        [, $output] = $this->runPreflight();

        $this->assertFirstFailureContains($output, 'key A', 'webhook_endpoints:read');
    }

    public function testFailsWhenKeyACannotReadTheBeneficiary(): void
    {
        $this->overrides['GET /api/beneficiaries/' . self::BENEFICIARY_ID] = self::error(404, 'not_found_error');
        [, $output] = $this->runPreflight();

        $this->assertFirstFailureContains($output, 'key A', 'MANZA_BENEFICIARY_ID');
    }

    public function testFailsOnceOnTheAuthorizerLineWhenTheSecretHasExpired(): void
    {
        $this->authorizer = array_merge($this->authorizer, [
            'status' => 'suspended', 'suspension_reason' => 'secret_expired', 'secret_expires_at' => '2026-10-19T00:00:00Z',
        ]);
        [, $output] = $this->runPreflight();

        $this->assertCount(1, self::failures($output));
        $this->assertFirstFailureContains($output, 'suspended (secret_expired)', 'rotates the secret');
    }

    public function testFailsWhenTheEnrolledWebhookEndpointWasDeleted(): void
    {
        $this->overrides['GET /api/webhook_endpoints/' . self::ENDPOINT_ID] = self::error(404, 'not_found_error');
        [, $output] = $this->runPreflight();

        $this->assertFirstFailureContains($output, 'was deleted', 'enrol');
    }

    public function testFailsWithTheSuspensionReasonAndItsFix(): void
    {
        $this->authorizer = array_merge($this->authorizer, ['status' => 'suspended', 'suspension_reason' => 'endpoint_disabled']);
        [, $output] = $this->runPreflight();

        $this->assertFirstFailureContains($output, 'suspended (endpoint_disabled)', 're-enable');
    }

    public function testFailsWhenTheAccountsCurrencyIsNotTheEnrolledOne(): void
    {
        $this->overrides['GET /api/accounts/' . self::ACCOUNT_ID] = self::json(200, ['id' => self::ACCOUNT_ID, 'currency_code' => 'ZAR']);
        [, $output] = $this->runPreflight();

        $this->assertFirstFailureContains($output, 'ZAR', 'MAD');
    }

    public function testFailsWhenTheSecretExpiresWithin7Days(): void
    {
        $this->authorizer['secret_expires_at'] = '2026-10-23T00:00:00Z';
        [, $output] = $this->runPreflight();

        $this->assertFirstFailureContains($output, 'secret expires', 'rotate');
    }

    public function testFailsWhenTheSigningSecretIsNotSet(): void
    {
        $this->env['MANZA_AUTHORIZER_SECRET'] = '';
        [, $output] = $this->runPreflight();

        $this->assertFirstFailureContains($output, 'MANZA_AUTHORIZER_SECRET');
    }

    public function testFailsWhenTheEnrolledWebhookEndpointIsDisabled(): void
    {
        $this->endpoint['status'] = 'disabled';
        [, $output] = $this->runPreflight();

        $this->assertFirstFailureContains($output, 'disabled', 'enable', 'if the authorizer stays suspended');
    }

    public function testWarnsWhenTheEndpointIsOnAUrlThatChangesWhenTheTunnelRestarts(): void
    {
        $this->endpoint['url'] = 'https://abc123.ngrok-free.app/';
        [$ok, $output] = $this->runPreflight();

        $this->assertTrue($ok);
        $warning = self::lines($output, '!')[0] ?? '';
        $this->assertStringContainsString('ngrok-free.app', $warning);
        $this->assertStringContainsString('reserved domain', $warning);
    }

    public function testFailsWhenTheAmountIsOverTheLimitPerTransfer(): void
    {
        [, $output] = $this->runPreflight('1500.00');

        $this->assertFirstFailureContains($output, 'limit per transfer', '1500.00', '1000.00');
    }

    public function testFailsWhenTheAmountDoesNotFitInTheLast24HoursRoom(): void
    {
        $this->authorizer['daily_authorized_total'] = '4995.0';
        [, $output] = $this->runPreflight();

        $this->assertFirstFailureContains($output, '24 hours', '5.00 left');
    }

    public function testShowsTheDecimalsThatDecideAComparison(): void
    {
        $this->authorizer['per_transfer_limit'] = '1.233';
        [, $output] = $this->runPreflight('1.234');

        $this->assertFirstFailureContains($output, '1.234 is over the limit per transfer (1.233)');
    }

    public function testRefusesMorePrecisionThanItCompares(): void
    {
        [, $output] = $this->runPreflight('1.0000009');

        $this->assertFirstFailureContains($output, '"1.0000009"', 'positive decimal');
    }

    public function testDoesNotWarnForAHostThatOnlyEndsLikeATunnelDomain(): void
    {
        $this->endpoint['url'] = 'https://myngrok-free.app/';
        [, $output] = $this->runPreflight();

        $this->assertSame([], self::lines($output, '!'));
    }

    public function testFailsTheAmountStepWhenALimitHasMorePrecisionThanItCompares(): void
    {
        $this->authorizer['per_transfer_limit'] = '1000.0000001';
        [$ok, $output] = $this->runPreflight();

        $this->assertFalse($ok);
        $this->assertFirstFailureContains($output, 'cannot compare', '1000.0000001');
    }

    public function testComparesAmountsExactly(): void
    {
        $this->authorizer['per_transfer_limit'] = '0.3';
        [, $output] = $this->runPreflight('0.30');

        $this->assertSame([], self::failures($output));
    }

    public function testWarnsThatANewPayeeNeedsTheToggleWhichTheApiDoesNotShow(): void
    {
        $this->payee['trusted'] = false;
        [$ok, $output] = $this->runPreflight();

        $this->assertTrue($ok);
        $warning = self::lines($output, '!')[0] ?? '';
        foreach (['not trusted yet', 'Approve transfers to new payees', 'IP allowlist'] as $needle) {
            $this->assertStringContainsString($needle, $warning);
        }
    }

    public function testFailsWhenTheBeneficiaryHasNoDefaultBankAccount(): void
    {
        $this->payee['default'] = false;
        [, $output] = $this->runPreflight();

        $this->assertFirstFailureContains($output, 'default bank account');
    }
}
