<?php

declare(strict_types=1);

namespace Manza\Tests\Examples;

use Manza\Examples\TransferAuthorizer\Ledger;
use PHPUnit\Framework\TestCase;

final class LedgerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/manza-ledger-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, "{$this->path}.lock"] as $file) {
            if (is_file($file)) {
                chmod($file, 0o600);
                unlink($file);
            }
        }
    }

    public function testRecordsAndFindsEntries(): void
    {
        $ledger = new Ledger($this->path);
        $ledger->record('po_1', paymentId: 'p1', amount: '1.0', currencyCode: 'MAD', accountId: 'a', payee: 'ext:x');
        $ledger->record('po_2', paymentId: 'p2', amount: '2.0', currencyCode: 'MAD', accountId: 'a', payee: 'ext:y');

        $this->assertSame('p1', $ledger->find('po_1')['payment_id'] ?? null);
        $this->assertSame('p2', $ledger->find('po_2')['payment_id'] ?? null);
        $this->assertNull($ledger->find('po_3'));
    }

    public function testRefusesToOverwriteALedgerItCannotRead(): void
    {
        $ledger = new Ledger($this->path);
        $ledger->record('po_1', paymentId: 'p1', amount: '1.0', currencyCode: 'MAD', accountId: 'a', payee: 'ext:x');
        chmod($this->path, 0o000);

        try {
            $ledger->record('po_2', paymentId: 'p2', amount: '2.0', currencyCode: 'MAD', accountId: 'a', payee: 'ext:y');
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot read', $e->getMessage());
        }

        chmod($this->path, 0o600);
        $this->assertSame('p1', $ledger->find('po_1')['payment_id'] ?? null, 'the old entries survive');
    }
}
