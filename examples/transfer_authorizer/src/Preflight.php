<?php

declare(strict_types=1);

namespace Manza\Examples\TransferAuthorizer;

use Manza\Client;
use Manza\Exception\ApiException;
use Manza\Exception\ConnectionException;

/**
 * Checks every setup step before you create a transfer, and says how to
 * fix the ones that fail. One line per step: ✓ passed, ✗ failed (run()
 * returns false), ! cannot be checked through the API.
 */
final class Preflight
{
    public const SECRET_MIN_DAYS = 7;
    /**
     * Hosts that hand out a new URL on every restart. An enrolled endpoint's
     * URL is locked, so the next restart breaks the authorizer.
     */
    public const UNSTABLE_TUNNEL_HOSTS = ['ngrok-free.app', 'trycloudflare.com'];
    public const SUSPENSION_FIXES = [
        'member' => 'an approver suspended it: resume it in Settings → Developer → Transfer approval',
        'signature_failures' => 'five invalid signatures in a row: fix the signing, then an approver resumes it in the app',
        'secret_expired' => 'an approver rotates the secret in the app (and resumes it if it stays suspended); '
            . 'set the new one as MANZA_AUTHORIZER_SECRET',
        'endpoint_disabled' => 're-enable the webhook endpoint ($client->webhookEndpoints->enable()), or enrol a new one if it was '
            . 'deleted; if it stays suspended, an approver resumes it in the app',
    ];

    /** The amount in minor units at {@see self::SCALE} decimals, or null when it is not a positive decimal. */
    private readonly ?int $amount;
    private readonly int $now;
    /** @var resource */
    private $out;
    /** @var \Closure(string, string): Client */
    private readonly \Closure $clientFactory;
    private bool $ok = true;
    private ?Client $creator = null;
    private ?Client $authorizerClient = null;

    /** Decimals the amounts are compared at: enough for any currency the API carries. */
    private const SCALE = 6;

    /**
     * @param array<string, string> $env MANZA_* settings, e.g. getenv()
     * @param resource|null $out where the lines go (default STDOUT)
     * @param int|null $now Unix seconds (default: the current time)
     * @param (\Closure(string, string): Client)|null $clientFactory builds a client from (apiKey, baseUrl)
     */
    public function __construct(
        private readonly array $env,
        private readonly string $rawAmount = '10.00',
        $out = null,
        ?int $now = null,
        ?\Closure $clientFactory = null,
    ) {
        // Plain decimals only: create_transfer.php sends the string as is, so
        // "1/2" or "1e2" would parse here but not mean the same to the API.
        $amount = preg_match('/\A\d+(?:\.\d+)?\z/', $rawAmount) === 1 ? self::units($rawAmount) : null;
        $this->amount = $amount !== null && $amount > 0 ? $amount : null;
        $this->out = $out ?? \STDOUT;
        $this->now = $now ?? time();
        $this->clientFactory = $clientFactory
            ?? static fn (string $apiKey, string $baseUrl): Client => new Client(apiKey: $apiKey, baseUrl: $baseUrl);
    }

    /**
     * Prints one line per step; true when no step failed.
     */
    public function run(): bool
    {
        if ($this->amount === null) {
            $this->failStep(sprintf('amount %s is not a positive decimal', self::inspect($this->rawAmount)), 'pass an amount like 10.00 or 250.00');
        }
        if (!$this->keys()) {
            return $this->ok;
        }

        $this->creatorReachesEntity();
        $authorizer = $this->enrolledAuthorizer();
        if ($authorizer !== null) {
            $this->currencyMatches($authorizer);
            // An expired secret already failed the authorizer line, with its fix.
            if (($authorizer['suspension_reason'] ?? null) !== 'secret_expired') {
                $this->secretFresh($authorizer);
            }
        }
        $this->secretSet();
        if ($authorizer !== null) {
            $this->endpointEnabled($authorizer);
            if ($this->amount !== null) {
                $this->amountWithinLimits($authorizer, $this->amount);
            }
        }
        $this->payeeTrusted();

        return $this->ok;
    }

    /** 1. Both keys (and the ids the other steps read) are set, the keys different. */
    private function keys(): bool
    {
        $missing = array_values(array_filter(
            ['MANZA_CREATOR_API_KEY', 'MANZA_AUTHORIZER_API_KEY', 'MANZA_ACCOUNT_ID', 'MANZA_BENEFICIARY_ID'],
            fn (string $name): bool => $this->value($name) === '',
        ));
        if ($missing !== []) {
            $this->failStep(implode(', ', $missing) . ' not set', 'set them in .env');
        } elseif ($this->value('MANZA_CREATOR_API_KEY') === $this->value('MANZA_AUTHORIZER_API_KEY')) {
            $this->failStep(
                'key A and key B are the same key',
                'use two different keys: the API refuses to let the key that created a transfer authorize it',
            );
        } else {
            $this->pass('key A and key B are set and different');

            return true;
        }

        return false;
    }

    /** 2. Key A reaches the API. */
    private function creatorReachesEntity(): void
    {
        try {
            $entity = $this->creator()->entity->get()->body;
            $this->pass(sprintf('key A reaches the API (entity %s)', self::inspect($entity['name'] ?? null)));
        } catch (ApiException|ConnectionException $e) {
            $this->failStep('key A cannot read the entity: ' . self::describe($e), self::keyFix($e, 'MANZA_CREATOR_API_KEY', 'entity:read'));
        }
    }

    /**
     * 3. Key B reads the authorizer, which must be active.
     *
     * @return array<string, mixed>|null
     */
    private function enrolledAuthorizer(): ?array
    {
        try {
            $authorizer = $this->authorizerClient()->transferAuthorizer->get()->body;
        } catch (ApiException $e) {
            if ($e->kind === 'not_found') {
                $this->failStep('no transfer authorizer is enrolled', 'enrol your webhook endpoint in Settings → Developer → Transfer approval');

                return null;
            }
            $this->failStep('key B cannot read the transfer authorizer: ' . self::describe($e), self::keyFix($e, 'MANZA_AUTHORIZER_API_KEY', 'transfers:authorize'));

            return null;
        } catch (ConnectionException $e) {
            $this->failStep('key B cannot read the transfer authorizer: ' . self::describe($e), self::keyFix($e, 'MANZA_AUTHORIZER_API_KEY', 'transfers:authorize'));

            return null;
        }

        if (($authorizer['status'] ?? null) === 'active') {
            $this->pass('transfer authorizer is active');
        } else {
            $reason = $authorizer['suspension_reason'] ?? null;
            $this->failStep(
                sprintf('transfer authorizer is %s (%s)', $authorizer['status'] ?? '', $reason ?? ''),
                self::SUSPENSION_FIXES[$reason] ?? 'see suspension_reason in the transfer authorization docs',
            );
        }

        return $authorizer;
    }

    /**
     * @param array<string, mixed> $authorizer
     */
    private function currencyMatches(array $authorizer): void
    {
        try {
            $currency = $this->creator()->accounts->get($this->value('MANZA_ACCOUNT_ID'))->body['currency_code'] ?? null;
        } catch (ApiException|ConnectionException $e) {
            $this->failStep(
                sprintf('key A cannot read account %s: %s', self::inspect($this->value('MANZA_ACCOUNT_ID')), self::describe($e)),
                self::keyFix($e, 'MANZA_CREATOR_API_KEY', 'accounts:read', 'MANZA_ACCOUNT_ID'),
            );

            return;
        }

        $enrolled = $authorizer['currency_code'] ?? null;
        if ($currency === $enrolled) {
            $this->pass("account currency {$currency} is the enrolled currency");

            return;
        }

        $this->failStep(
            "account currency {$currency} is not the enrolled currency {$enrolled}",
            "pay from a {$enrolled} account (MANZA_ACCOUNT_ID), or enrol {$currency}",
        );
    }

    /**
     * @param array<string, mixed> $authorizer
     */
    private function secretFresh(array $authorizer): void
    {
        $expiresAt = (string) ($authorizer['secret_expires_at'] ?? '');
        $days = (int) floor(((new \DateTimeImmutable($expiresAt))->getTimestamp() - $this->now) / 86400);
        if ($days >= self::SECRET_MIN_DAYS) {
            $this->pass("signing secret expires in {$days} days");

            return;
        }

        $this->failStep(
            sprintf('signing secret %s (%s)', $days < 0 ? 'expired' : "expires in {$days} days", $expiresAt),
            'an approver should rotate the secret in Settings → Developer → Transfer approval; set the new one as '
                . 'MANZA_AUTHORIZER_SECRET',
        );
    }

    /** 4. The signing secret is set. */
    private function secretSet(): void
    {
        if ($this->value('MANZA_AUTHORIZER_SECRET') !== '') {
            $this->pass('MANZA_AUTHORIZER_SECRET is set');

            return;
        }

        $this->failStep('MANZA_AUTHORIZER_SECRET is not set', 'set it to the signing secret shown at enrolment or at the last rotation');
    }

    /**
     * 5. The enrolled webhook endpoint is enabled, on a URL that lasts.
     *
     * @param array<string, mixed> $authorizer
     */
    private function endpointEnabled(array $authorizer): void
    {
        $endpointId = (string) ($authorizer['webhook_endpoint_id'] ?? '');
        try {
            $endpoint = $this->creator()->webhookEndpoints->get($endpointId)->body;
        } catch (ApiException $e) {
            if ($e->kind === 'not_found') {
                $this->failStep(
                    "the enrolled webhook endpoint {$endpointId} was deleted",
                    'create an endpoint at a stable URL and enrol it in Settings → Developer → Transfer approval',
                );

                return;
            }
            $this->failStep('key A cannot read the enrolled webhook endpoint: ' . self::describe($e), self::keyFix($e, 'MANZA_CREATOR_API_KEY', 'webhook_endpoints:read'));

            return;
        } catch (ConnectionException $e) {
            $this->failStep('key A cannot read the enrolled webhook endpoint: ' . self::describe($e), self::keyFix($e, 'MANZA_CREATOR_API_KEY', 'webhook_endpoints:read'));

            return;
        }

        $url = (string) ($endpoint['url'] ?? '');
        if (($endpoint['status'] ?? null) === 'active') {
            $this->pass("webhook endpoint {$url} is enabled");
        } else {
            $this->failStep(
                sprintf('webhook endpoint %s is %s', $url, $endpoint['status'] ?? ''),
                sprintf(
                    'enable it ($client->webhookEndpoints->enable(%s)); if the authorizer stays suspended, an approver resumes it in the app',
                    self::inspect($endpoint['id'] ?? null),
                ),
            );
        }

        $host = (string) parse_url($url, \PHP_URL_HOST);
        foreach (self::UNSTABLE_TUNNEL_HOSTS as $domain) {
            if (str_ends_with($host, $domain)) {
                $this->warnStep(
                    "webhook endpoint is on {$domain}, whose URL changes when the tunnel restarts",
                    'use a reserved domain or a named tunnel, then enrol an endpoint at that URL',
                );

                return;
            }
        }
    }

    /**
     * 6a. The test amount fits both limits.
     *
     * @param array<string, mixed> $authorizer
     */
    private function amountWithinLimits(array $authorizer, int $amount): void
    {
        $perTransfer = self::units((string) ($authorizer['per_transfer_limit'] ?? ''));
        $room = self::units((string) ($authorizer['daily_limit'] ?? '')) - self::units((string) ($authorizer['daily_authorized_total'] ?? ''));
        if ($amount > $perTransfer) {
            $this->failStep(
                sprintf('%s is over the limit per transfer (%s)', self::format($amount), self::format($perTransfer)),
                sprintf('send at most %s, or have an approver raise the limit', self::format($perTransfer)),
            );
        } elseif ($amount > $room) {
            $this->failStep(
                sprintf("%s does not fit the last 24 hours' room (%s left)", self::format($amount), self::format($room)),
                'wait for older authorizations to leave the 24-hour window, or have an approver raise the limit',
            );
        } else {
            $this->pass(sprintf('%s is within the limit per transfer and the 24-hour room', self::format($amount)));
        }
    }

    /** 6b. The payee is trusted, or may be trusted on authorize. */
    private function payeeTrusted(): void
    {
        try {
            $beneficiary = $this->creator()->beneficiaries->get($this->value('MANZA_BENEFICIARY_ID'))->body;
        } catch (ApiException|ConnectionException $e) {
            $this->failStep(
                sprintf('key A cannot read beneficiary %s: %s', self::inspect($this->value('MANZA_BENEFICIARY_ID')), self::describe($e)),
                self::keyFix($e, 'MANZA_CREATOR_API_KEY', 'beneficiaries:read', 'MANZA_BENEFICIARY_ID'),
            );

            return;
        }

        $account = null;
        foreach (\is_array($beneficiary['external_accounts'] ?? null) ? $beneficiary['external_accounts'] : [] as $candidate) {
            if (\is_array($candidate) && ($candidate['default'] ?? false)) {
                $account = $candidate;
                break;
            }
        }
        if ($account === null) {
            $this->failStep('beneficiary has no default bank account', 'add one, or set a beneficiary that has one');

            return;
        }
        if ($account['trusted'] ?? false) {
            $this->pass("payee {$account['id']} is trusted");

            return;
        }

        $this->warnStep(
            "payee {$account['id']} is not trusted yet",
            'it qualifies only with "Approve transfers to new payees" on in Settings → Developer → Transfer approval '
                . '(not visible through the API), key A bound to an IP allowlist, and the amount within the limit per '
                . 'transfer to a new payee, and only if its bank details were never trusted (revoked or edited '
                . 'details always go to your approvers); otherwise have an approver trust it',
        );
    }

    private function creator(): Client
    {
        return $this->creator ??= ($this->clientFactory)($this->value('MANZA_CREATOR_API_KEY'), $this->baseUrl());
    }

    private function authorizerClient(): Client
    {
        return $this->authorizerClient ??= ($this->clientFactory)($this->value('MANZA_AUTHORIZER_API_KEY'), $this->baseUrl());
    }

    private function baseUrl(): string
    {
        return $this->value('MANZA_BASE_URL') !== '' ? $this->value('MANZA_BASE_URL') : Client::DEFAULT_BASE_URL;
    }

    private function value(string $name): string
    {
        return (string) ($this->env[$name] ?? '');
    }

    private static function describe(ApiException|ConnectionException $e): string
    {
        return $e instanceof ApiException
            ? sprintf('ApiException %s: %s', $e->type ?? $e->status, $e->getMessage())
            : "ConnectionException: {$e->getMessage()}";
    }

    private static function keyFix(ApiException|ConnectionException $e, string $keyVar, string $scope, ?string $idVar = null): string
    {
        return match ($e instanceof ApiException ? $e->kind : null) {
            'authentication' => "check {$keyVar}: the key is wrong or revoked",
            'forbidden' => "give the key the {$scope} scope, or add this machine's address to its IP allowlist",
            'not_found' => 'check ' . ($idVar ?? 'MANZA_BASE_URL'),
            default => 'check MANZA_BASE_URL and the network, then rerun',
        };
    }

    /**
     * A decimal string ("2500.0") in minor units at {@see self::SCALE}
     * decimals, exact: no float rounding on the comparison.
     */
    private static function units(string $decimal): int
    {
        if (preg_match('/\A(-?)(\d+)(?:\.(\d+))?\z/', $decimal, $m) !== 1) {
            throw new \UnexpectedValueException('not a decimal: ' . self::inspect($decimal));
        }
        $fraction = substr(str_pad($m[3] ?? '', self::SCALE, '0'), 0, self::SCALE);
        $units = (int) ($m[2] . $fraction);

        return $m[1] === '-' ? -$units : $units;
    }

    private static function format(int $units): string
    {
        return sprintf('%.2f', $units / 10 ** self::SCALE);
    }

    private static function inspect(mixed $value): string
    {
        return json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '?';
    }

    private function pass(string $message): void
    {
        fwrite($this->out, "✓ {$message}\n");
    }

    private function failStep(string $message, string $fix): void
    {
        $this->ok = false;
        fwrite($this->out, "✗ {$message}: {$fix}\n");
    }

    private function warnStep(string $message, string $note): void
    {
        fwrite($this->out, "! {$message}: {$note}\n");
    }
}
