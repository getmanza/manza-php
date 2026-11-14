<?php

declare(strict_types=1);

namespace Manza\Tests;

use PHPUnit\Framework\TestCase;
use Manza\TransferAuthorization;

/**
 * Fixed test vectors, shared by every SDK in the family (mirror of
 * manza-ruby's spec/manza/transfer_authorization_spec.rb). Each SDK's
 * signer must produce exactly these hex digests from these inputs.
 */
final class TransferAuthorizationTest extends TestCase
{
    private const SECRET = 'whsec_test_vector_secret';
    private const PAYMENT_ID = '0199a1b2-0000-7000-8000-000000000001';
    private const NONCE = 'n0nce-0123456789abcdef';
    private const ACCOUNT_ID = '0199a1b2-0000-7000-8000-000000000002';

    public function testSignsVersionV2(): void
    {
        $this->assertSame('manza.transfer-authorization.v2', TransferAuthorization::SIGNATURE_VERSION);
    }

    public function testExternalAccountPayeeWithClientReferenceAndTransferMethod(): void
    {
        $input = TransferAuthorization::signatureInput(
            paymentId: self::PAYMENT_ID,
            nonce: self::NONCE,
            amount: '2500.0',
            currencyCode: 'MAD',
            accountId: self::ACCOUNT_ID,
            payee: TransferAuthorization::payeeFor(externalAccountId: '0199a1b2-0000-7000-8000-000000000003'),
            clientReference: 'po_1',
            transferMethod: 'eft',
        );

        $this->assertSame(
            'manza.transfer-authorization.v2|0199a1b2-0000-7000-8000-000000000001|n0nce-0123456789abcdef|'
            . '2500.0|MAD|0199a1b2-0000-7000-8000-000000000002|ext:0199a1b2-0000-7000-8000-000000000003|po_1|eft',
            $input,
        );
        $this->assertSame(
            '904da7d0b73b2c919ffa1ae64d262ce5825ee4d2c8420fd70a3d257353e6c43a',
            TransferAuthorization::sign(self::SECRET, $input),
        );
    }

    public function testANullTransferMethodSignsAnEmptySegment(): void
    {
        $input = TransferAuthorization::signatureInput(
            paymentId: self::PAYMENT_ID,
            nonce: self::NONCE,
            amount: '2500.0',
            currencyCode: 'MAD',
            accountId: self::ACCOUNT_ID,
            payee: TransferAuthorization::payeeFor(externalAccountId: '0199a1b2-0000-7000-8000-000000000003'),
            clientReference: 'po_1',
            transferMethod: null,
        );

        $this->assertStringEndsWith('|ext:0199a1b2-0000-7000-8000-000000000003|po_1|', $input);
        $this->assertSame(
            '029dd12924a846d7af2f4aecb61e4f4f9bd64d37315230864fb03917adc29edf',
            TransferAuthorization::sign(self::SECRET, $input),
        );
    }

    public function testOwnAccountPayeeWithoutClientReferenceOrTransferMethod(): void
    {
        $input = TransferAuthorization::signatureInput(
            paymentId: self::PAYMENT_ID,
            nonce: self::NONCE,
            amount: '2500.0',
            currencyCode: 'MAD',
            accountId: self::ACCOUNT_ID,
            payee: TransferAuthorization::payeeFor(destinationAccountId: '0199a1b2-0000-7000-8000-000000000004'),
            clientReference: null,
            transferMethod: null,
        );

        $this->assertStringEndsWith('|own:0199a1b2-0000-7000-8000-000000000004||', $input);
        $this->assertSame(
            'a21ccf43b94f23d7a595a36bd788c27c7adbc5686a5ad4427b22d9957568c9fb',
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
            clientReference: null,
            transferMethod: null,
        );
    }

    /**
     * A v1 call (no transferMethod) fails here, not as a failed signature
     * attempt at the API, where five of them fail the challenge.
     */
    public function testSignatureInputRequiresTransferMethod(): void
    {
        $this->expectException(\ArgumentCountError::class);

        TransferAuthorization::signatureInput( // @phpstan-ignore arguments.count
            paymentId: self::PAYMENT_ID,
            nonce: self::NONCE,
            amount: '2500.0',
            currencyCode: 'MAD',
            accountId: self::ACCOUNT_ID,
            payee: 'ext:x',
            clientReference: 'po_1',
        );
    }

    /** A 1.x positional call (clientReference seventh) must not sign `…|payee||po_1`. */
    public function testRefusesA1xPositionalCallThatPassesTheClientReferenceSeventh(): void
    {
        $this->expectException(\ArgumentCountError::class);

        TransferAuthorization::signatureInput( // @phpstan-ignore arguments.count
            self::PAYMENT_ID,
            self::NONCE,
            '2500.0',
            'MAD',
            self::ACCOUNT_ID,
            'ext:x',
            'po_1',
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
