<?php

declare(strict_types=1);

namespace Manza\Examples\TransferAuthorizer;

/**
 * Your own record of the transfers you created, keyed by the
 * client_reference you generated. A JSON file, so the creator
 * (create_transfer.php) and the authorizer (authorizer.php) can run as two
 * processes. Swap it for your database in production.
 *
 *     {"po_8812": {"payment_id": "...", "amount": "2500.0",
 *                  "currency_code": "MAD", "account_id": "...",
 *                  "payee": "ext:..."}}
 */
final class Ledger
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * @return array<string, string> the entry written
     */
    public function record(
        string $clientReference,
        string $paymentId,
        string $amount,
        string $currencyCode,
        string $accountId,
        string $payee,
    ): array {
        $entry = [
            'payment_id' => $paymentId,
            'amount' => $amount,
            'currency_code' => $currencyCode,
            'account_id' => $accountId,
            'payee' => $payee,
        ];

        // Serialize writers on a separate lock file, write the new ledger to a
        // temporary file and rename it over the old one: a reader sees the old
        // or the new ledger, never a half-written one, and a crash mid-write
        // leaves the old ledger intact.
        $lock = fopen("{$this->path}.lock", 'c');
        if ($lock === false || !flock($lock, \LOCK_EX)) {
            throw new \RuntimeException("cannot lock {$this->path}.lock");
        }
        try {
            $entries = $this->readEntries();
            $entries[$clientReference] = $entry;
            $tmp = "{$this->path}." . getmypid() . '.tmp';
            $json = json_encode($entries, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
            // A short write would rename a truncated ledger over the old one.
            if (file_put_contents($tmp, $json) !== \strlen($json)
                || !chmod($tmp, 0o600)
                || !rename($tmp, $this->path)) {
                throw new \RuntimeException("cannot write {$this->path}");
            }
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }

        return $entry;
    }

    /**
     * The entry for $clientReference, or null.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $clientReference): ?array
    {
        $entry = $this->readEntries()[$clientReference] ?? null;

        return \is_array($entry) ? $entry : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readEntries(): array
    {
        clearstatcache(true, $this->path);
        if (!is_file($this->path)) {
            return [];
        }

        // A failed read is not an empty ledger: record() would write over every entry.
        $json = @file_get_contents($this->path);
        if ($json === false) {
            throw new \RuntimeException("cannot read {$this->path}");
        }
        if (trim($json) === '') {
            return [];
        }

        $entries = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($entries) ? $entries : [];
    }
}
