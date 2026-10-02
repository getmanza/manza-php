<?php

declare(strict_types=1);

namespace Manza\Tests;

use PHPUnit\Framework\TestCase;
use Manza\TransferAuthorization;

/**
 * Fixed test vector, shared by every SDK in the family (mirror of
 * manza-ruby's spec/manza/transfer_authorization_spec.rb). Each SDK's
 * signer must produce exactly these hex digests from these inputs.
 */
final class TransferAuthorizationTest extends TestCase
{
    private const SECRET = 'whsec_test_vector_secret';
    private const PAYMENT_ID = '0199a1b2-0000-7000-8000-000000000001';
    private const NONCE = 'n0nce-0123456789abcdef';
    private const ACCOUNT_ID = '0199a1b2-0000-7000-8000-000000000002';

    public function testExternalAccountPayeeWithClientReference(): void
    {
        $input = TransferAuthorization::signatureInput(
            paymentId: self::PAYMENT_ID,
            nonce: self::NONCE,
            amount: '2500.0',
            currencyCode: 'MAD',
            accountId: self::ACCOUNT_ID,
            payee: TransferAuthorization::payeeFor(externalAccountId: '0199a1b2-0000-7000-8000-000000000003'),
            clientReference: 'po_1',
        );

        $this->assertSame(
            'manza.transfer-authorization.v1|0199a1b2-0000-7000-8000-000000000001|n0nce-0123456789abcdef|'
            . '2500.0|MAD|0199a1b2-0000-7000-8000-000000000002|ext:0199a1b2-0000-7000-8000-000000000003|po_1',
            $input,
        );
        $this->assertSame(
            '6e8eaec0f89a4eb3b22df1133b3d6dfebfa8505c34c58ed0ff192516e4223078',
            TransferAuthorization::sign(self::SECRET, $input),
        );
    }

    public function testOwnAccountPayeeWithoutClientReference(): void
    {
        $input = TransferAuthorization::signatureInput(
            paymentId: self::PAYMENT_ID,
            nonce: self::NONCE,
            amount: '2500.0',
            currencyCode: 'MAD',
            accountId: self::ACCOUNT_ID,
            payee: TransferAuthorization::payeeFor(destinationAccountId: '0199a1b2-0000-7000-8000-000000000004'),
        );

        $this->assertStringEndsWith('|own:0199a1b2-0000-7000-8000-000000000004|', $input);
        $this->assertSame(
            'af9440b1de1bebb51f381ce43e3d0d27b6a4ccb99dcd548c0b5435ff4fdd1895',
            TransferAuthorization::sign(self::SECRET, $input),
        );
    }

    public function testRefusesANonStringAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('amount');

        TransferAuthorization::signatureInput(
            paymentId: self::PAYMENT_ID,
            nonce: self::NONCE,
            amount: 2500, // @phpstan-ignore argument.type
            currencyCode: 'MAD',
            accountId: self::ACCOUNT_ID,
            payee: 'ext:x',
        );
    }

    public function testPayeeForRefusesBothIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TransferAuthorization::payeeFor(externalAccountId: 'a', destinationAccountId: 'b');
    }

    public function testPayeeForRefusesNeitherId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TransferAuthorization::payeeFor();
    }
}
