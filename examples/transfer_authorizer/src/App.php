<?php

declare(strict_types=1);

namespace Manza\Examples\TransferAuthorizer;

use Manza\Client;
use Manza\Exception\ApiException;
use Manza\Exception\ConnectionException;
use Manza\Response;
use Manza\TransferAuthorization;
use Manza\Webhook;

/**
 * Answers `payment.authorization_requested` webhooks with key B (the
 * authorizer key):
 *
 *   1. Verify the delivery (401 when the signature or timestamp is bad).
 *   2. Find the draft in your own ledger by its client_reference.
 *   3. Missing or different from what you created → decline with a reason.
 *   4. A payee not trusted yet (`payee_trusted: false`, only with "Approve
 *      transfers to new payees" on) is trusted by the authorize, unless
 *      `allowNewPayees: false` (ALLOW_NEW_PAYEES=false) declines it.
 *   5. Otherwise sign the challenge from the ledger and authorize it.
 *
 * {@see App::handle()} returns the status to answer and hands steps 2-5 to
 * the executor: authorizer.php queues them and runs them after it has sent
 * the response, because the delivery has a timeout. A retried webhook is
 * safe: authorize replays idempotently.
 */
final class App
{
    public const EVENT = 'payment.authorization_requested';

    /** Ledger field => webhook `data.payment` field. All must match. */
    public const CHECKED_FIELDS = [
        'payment_id' => 'id',
        'amount' => 'amount',
        'currency_code' => 'currency_code',
        'account_id' => 'account_id',
        'payee' => 'payee',
    ];

    /**
     * A 409 worth another try: a bank-detail edit, trust or revocation on the
     * same payee was in flight. Authorize and decline replay idempotently.
     */
    public const BUSY = 'authorization_busy';
    public const ATTEMPTS = 3;
    /** Seconds; a challenge expires, so never park the process for long. */
    public const MAX_RETRY_AFTER = 30;

    /** @var \Closure(\Closure(): void): void */
    private readonly \Closure $executor;
    /** @var \Closure(string, string): void */
    private readonly \Closure $logger;

    /**
     * @param (\Closure(\Closure(): void): void)|null $executor runs a job; default: right away
     * @param (\Closure(string, string): void)|null $logger receives (level, message); default: stderr
     * @param float $ledgerWait seconds to wait for the ledger entry: the webhook can arrive
     *   before create_transfer.php has written it from the create response
     * @param int $retryDelay base seconds between attempts
     * @param bool $allowNewPayees false declines transfers to payees not trusted yet
     */
    public function __construct(
        private readonly Client $client,
        private readonly string $secret,
        private readonly Ledger $ledger,
        ?\Closure $executor = null,
        ?\Closure $logger = null,
        private readonly float $ledgerWait = 10.0,
        private readonly int $retryDelay = 2,
        private readonly bool $allowNewPayees = true,
    ) {
        $this->executor = $executor ?? static function (\Closure $job): void {
            $job();
        };
        $this->logger = $logger ?? self::stderrLogger();
    }

    /**
     * Writes "<time> <LEVEL> <message>" lines to stderr, which `php -S`
     * shows in its terminal.
     *
     * @return \Closure(string, string): void
     */
    public static function stderrLogger(): \Closure
    {
        return static function (string $level, string $message): void {
            file_put_contents('php://stderr', sprintf("%s %s %s\n", date('c'), strtoupper($level), $message));
        };
    }

    /**
     * Verifies one delivery and returns the HTTP status to answer with.
     *
     * @param string $body the raw request body
     * @param array<array-key, mixed> $headers `$_SERVER`, `getallheaders()` or a PSR-7 `getHeaders()`
     */
    public function handle(string $body, array $headers): int
    {
        try {
            $event = Webhook::constructEvent(payload: $body, headers: $headers, secret: $this->secret);
        } catch (\InvalidArgumentException $e) {
            $this->log('warning', "rejected a delivery: {$e->getMessage()}");

            return 401;
        }
        $this->log('info', sprintf('received %s (%s), signature verified', self::text($event['event'] ?? null), self::text($event['event_id'] ?? null)));

        if (($event['event'] ?? null) !== self::EVENT) {
            $this->log('info', sprintf('ignoring %s: this app only answers %s', self::text($event['event'] ?? null), self::EVENT));

            return 200;
        }

        $data = \is_array($event['data'] ?? null) ? $event['data'] : [];
        ($this->executor)(function () use ($data): void {
            $this->answer($data);
        });

        return 200;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function answer(array $data): void
    {
        try {
            $payment = self::section($data, 'payment');
            $authorization = self::section($data, 'authorization');
            $clientReference = $payment['client_reference'] ?? null;
            $entry = \is_string($clientReference) ? $this->waitForEntry($clientReference) : null;

            $mismatch = $entry !== null
                ? self::mismatchReason($entry, $payment)
                : sprintf('client_reference %s is not in the ledger', self::inspect($clientReference));
            if ($mismatch !== null) {
                $this->decline((string) $payment['id'], (string) $authorization['id'], $mismatch);

                return;
            }

            // Anything but true counts as new, so a webhook without the field is
            // declined under ALLOW_NEW_PAYEES=false rather than trusted blindly.
            if (($payment['payee_trusted'] ?? null) !== true) {
                if (!$this->allowNewPayees) {
                    $this->decline(
                        (string) $payment['id'],
                        (string) $authorization['id'],
                        sprintf('new payee %s: ALLOW_NEW_PAYEES=false declines payees not trusted yet', self::text($payment['payee'] ?? null)),
                    );

                    return;
                }

                $this->log('info', sprintf('new payee %s, will be trusted on authorize', self::text($payment['payee'] ?? null)));
            }

            $this->log('info', sprintf(
                'ledger match for %s: %s %s to %s',
                $clientReference,
                self::text($payment['amount'] ?? null),
                self::text($payment['currency_code'] ?? null),
                self::text($payment['payee'] ?? null),
            ));
            $this->authorize($entry, $authorization, (string) $clientReference, $data['signature_input'] ?? null);
        } catch (ApiException|ConnectionException $e) {
            $this->log('error', sprintf(
                'API error %s: %s (type %s, request_id %s)',
                self::describe($e),
                $e->getMessage(),
                self::inspect($e instanceof ApiException ? $e->type : null),
                self::inspect($e instanceof ApiException ? $e->requestId : null),
            ));
        } catch (\Throwable $e) {
            $this->log('error', sprintf('could not answer the challenge: %s: %s', $e::class, $e->getMessage()));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function waitForEntry(string $clientReference): ?array
    {
        $deadline = microtime(true) + $this->ledgerWait;
        while (true) {
            $entry = $this->ledger->find($clientReference);
            if ($entry !== null || microtime(true) >= $deadline) {
                return $entry;
            }

            usleep(500_000);
        }
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $payment
     */
    private static function mismatchReason(array $entry, array $payment): ?string
    {
        foreach (self::CHECKED_FIELDS as $ledgerField => $webhookField) {
            if (($entry[$ledgerField] ?? null) !== ($payment[$webhookField] ?? null)) {
                return sprintf(
                    '%s differs from the ledger (ledger %s, webhook %s)',
                    $ledgerField,
                    self::inspect($entry[$ledgerField] ?? null),
                    self::inspect($payment[$webhookField] ?? null),
                );
            }
        }

        return null;
    }

    /**
     * Sign from the ledger, never from the webhook: `signature_input` in the
     * webhook is only there to compare against.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $authorization
     */
    private function authorize(array $entry, array $authorization, string $clientReference, mixed $webhookInput): void
    {
        $input = TransferAuthorization::signatureInput(
            paymentId: (string) $entry['payment_id'],
            nonce: (string) ($authorization['nonce'] ?? ''),
            amount: $entry['amount'],
            currencyCode: (string) $entry['currency_code'],
            accountId: (string) $entry['account_id'],
            payee: (string) $entry['payee'],
            clientReference: $clientReference,
        );
        // A signature over a different input is sure to fail, and failed
        // attempts count toward suspending the authorizer. Leave the challenge
        // unanswered instead: it expires and goes to your approvers.
        if ($input !== $webhookInput) {
            $this->log('warning', sprintf(
                "signature input differs from the webhook's: ledger %s, webhook %s; not answering, the challenge goes to your approvers when it expires",
                self::inspect($input),
                self::inspect($webhookInput),
            ));

            return;
        }

        $signature = TransferAuthorization::sign($this->secret, $input);
        $this->log('info', "signed the challenge for payment {$entry['payment_id']}");

        $response = $this->withRetries(
            fn (): Response => $this->client->payments->authorize((string) $entry['payment_id'], (string) $authorization['id'], $signature),
        );
        $this->log('info', sprintf('authorized payment %s (status %s)', $entry['payment_id'], self::inspect($response->body['status'] ?? null)));
    }

    private function decline(string $paymentId, string $authorizationId, string $reason): void
    {
        $this->withRetries(fn (): Response => $this->client->payments->decline($paymentId, $authorizationId, $reason));
        $this->log('warning', "declined payment {$paymentId}: {$reason}");
    }

    /**
     * Retries what is worth another try: the API did not answer (connection,
     * 5xx), asked us to slow down (429), or was busy (409 authorization_busy).
     *
     * @param \Closure(): Response $call
     */
    private function withRetries(\Closure $call): Response
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $call();
            } catch (ApiException|ConnectionException $e) {
                if ($attempt >= self::ATTEMPTS || !self::transient($e)) {
                    throw $e;
                }

                $delay = $e instanceof ApiException && $e->kind === 'rate_limit' && $e->retryAfter !== null
                    ? min($e->retryAfter, self::MAX_RETRY_AFTER)
                    : $this->retryDelay * $attempt;
                $this->log('warning', sprintf(
                    '%s (request_id %s), retrying in %ds',
                    self::describe($e),
                    self::inspect($e instanceof ApiException ? $e->requestId : null),
                    $delay,
                ));
                sleep($delay);
            }
        }
    }

    private static function transient(ApiException|ConnectionException $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return \in_array($e->kind, ['server', 'rate_limit'], true)
            || ($e->kind === 'conflict' && $e->type === self::BUSY);
    }

    private static function describe(ApiException|ConnectionException $e): string
    {
        return $e instanceof ApiException ? "ApiException {$e->kind} ({$e->status})" : 'ConnectionException';
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function section(array $data, string $key): array
    {
        if (!\is_array($data[$key] ?? null)) {
            throw new \UnexpectedValueException("the webhook has no data.{$key}");
        }

        return $data[$key];
    }

    /** A value as JSON: strings quoted, null as null. */
    private static function inspect(mixed $value): string
    {
        return json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '?';
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : self::inspect($value);
    }

    private function log(string $level, string $message): void
    {
        ($this->logger)($level, $message);
    }
}
