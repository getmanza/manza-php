<?php

declare(strict_types=1);

namespace Manza\Tests;

use GuzzleHttp\Client as GuzzleClient;
use PHPUnit\Framework\TestCase;
use Manza\Client;
use Manza\Exception\ApiException;
use Manza\Page;
use Manza\TransferAuthorization;

/**
 * Mirror of manza-ruby's spec/manza/resources/*_spec.rb (and manza-go's
 * resources_test.go) — same cassettes, same assertions, per the
 * cross-language SDK contract.
 */
final class ResourcesTest extends TestCase
{
    private const REPLAY_BASE_URL = 'https://ma.manza.dev';

    private function replayClient(string ...$cassettes): Client
    {
        return $this->clientWith(CassetteReplayHandler::client(...$cassettes));
    }

    /** For the authorize cassettes: bodies match with `signature` removed. */
    private function signatureReplayClient(string $cassette): Client
    {
        return $this->clientWith(CassetteReplayHandler::clientIgnoringSignature($cassette));
    }

    private function clientWith(GuzzleClient $http): Client
    {
        return new Client(
            apiKey: 'test-api-key-for-replay',
            baseUrl: self::REPLAY_BASE_URL,
            httpClient: $http,
        );
    }

    public function testEntityGet(): void
    {
        $client = $this->replayClient('entity/get');

        $resp = $client->entity->get();

        $this->assertIsString($resp->body['id'] ?? null, 'expected string id');
    }

    public function testAccounts(): void
    {
        $client = $this->replayClient(
            'accounts/list',
            'accounts/get',
            'accounts/list_transactions',
            'accounts/get_transaction',
        );

        $page = $client->accounts->list();
        $this->assertNotEmpty($page->data, 'expected data rows');

        $accountId = FixtureIds::id('MANZA_FIXTURE_ACCOUNT_ID');
        $client->accounts->get($accountId);

        $client->accounts->listTransactions($accountId);

        $txId = FixtureIds::id('MANZA_FIXTURE_TRANSACTION_ID');
        $client->accounts->getTransaction($accountId, $txId);
    }

    public function testCustomers(): void
    {
        $client = $this->replayClient(
            'customers/list',
            'customers/get',
            'customers/create',
            'customers/update',
            'customers/delete',
        );

        $client->customers->list();

        $customerId = FixtureIds::id('MANZA_FIXTURE_CUSTOMER_ID');
        $resp = $client->customers->get($customerId);
        $this->assertIsString($resp->body['id'] ?? null, 'expected string id');
    }

    public function testInvoices(): void
    {
        $client = $this->replayClient('invoices/list', 'invoices/get');

        $page = $client->invoices->list();
        $this->assertNotEmpty($page->data, 'expected data rows');

        $invoiceId = FixtureIds::id('MANZA_FIXTURE_INVOICE_ID');
        $client->invoices->get($invoiceId);
    }

    public function testPaymentLinks(): void
    {
        $client = $this->replayClient(
            'payment_links/list',
            'payment_links/get',
            'payment_links/create',
            'payment_links/cancel',
        );

        $client->paymentLinks->list();

        $resp = $client->paymentLinks->create([
            'account_id' => FixtureIds::id('MANZA_FIXTURE_ACCOUNT_ID'),
            'amount' => '100.00',
            'title' => 'SDK fixture',
            'description' => 'Created by zazu-ruby fixture spec',
            'link_type' => 'single',
        ]);
        $this->assertSame(201, $resp->status);

        $client->paymentLinks->cancel(FixtureIds::id('MANZA_FIXTURE_CANCELLABLE_PAYMENT_LINK_ID'));
    }

    public function testCheckoutSessions(): void
    {
        $client = $this->replayClient('checkout_sessions/create', 'checkout_sessions/get');

        $created = $client->checkoutSessions->create([
            'account_id' => FixtureIds::id('MANZA_FIXTURE_ACCOUNT_ID'),
            'amount' => '100.00',
            'success_url' => 'https://example.com/zazu-fixture-success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => 'https://example.com/zazu-fixture-cancel',
            'description' => 'Created by zazu-ruby fixture spec',
            'customer_email' => 'fixture@example.com',
            'metadata' => ['order_id' => 'ORD-FIXTURE'],
        ]);
        $this->assertSame(201, $created->status);
        $this->assertIsString($created->body['id'] ?? null, 'expected string id');
        $this->assertIsString($created->body['url'] ?? null, 'expected string url');
        $this->assertSame('open', $created->body['status'] ?? null);

        $resp = $client->checkoutSessions->get(FixtureIds::id('MANZA_FIXTURE_CHECKOUT_SESSION_ID'));

        $this->assertIsString($resp->body['id'] ?? null, 'expected string id');
        $this->assertIsString($resp->body['status'] ?? null, 'expected string status');
    }

    public function testWebhookEndpoints(): void
    {
        $client = $this->replayClient('webhook_endpoints/list', 'webhook_endpoints/get');

        $client->webhookEndpoints->list();
        $client->webhookEndpoints->get(FixtureIds::id('MANZA_FIXTURE_WEBHOOK_ID'));

        $this->addToAssertionCount(1); // both calls matched their cassette interactions
    }

    public function testPaymentsCreateCarriesClientReference(): void
    {
        $client = $this->replayClient('payments/create');

        $resp = $client->payments->create([
            'account_id' => FixtureIds::id('MANZA_FIXTURE_ACCOUNT_ID'),
            'beneficiary_id' => FixtureIds::id('MANZA_FIXTURE_BENEFICIARY_ID'),
            'amount' => '10.00',
            'payment_reference' => 'SDK fixture',
            'internal_notes' => '[zazu-ruby-fixture] create spec - do not approve',
            'client_reference' => FixtureIds::id('MANZA_FIXTURE_CLIENT_REFERENCE'),
        ]);

        $this->assertSame(201, $resp->status);
        $this->assertSame('requested', $resp->body['status'] ?? null);
        $this->assertSame(
            FixtureIds::id('MANZA_FIXTURE_CLIENT_REFERENCE'),
            $resp->body['client_reference'] ?? null,
        );
        // Inside the envelope: the challenge went to the authorizer.
        $this->assertSame('pending', $resp->body['authorization']['status'] ?? null);
        $this->assertArrayHasKey('transfer', $resp->body);
        $this->assertNull($resp->body['transfer'], 'expected null transfer before approval');
    }

    public function testPaymentsCreateDuplicateRaisesConflict(): void
    {
        $client = $this->replayClient('payments/create_duplicate');

        try {
            $client->payments->create([
                'account_id' => FixtureIds::id('MANZA_FIXTURE_ACCOUNT_ID'),
                'beneficiary_id' => FixtureIds::id('MANZA_FIXTURE_BENEFICIARY_ID'),
                'amount' => '10.00',
                'client_reference' => FixtureIds::id('MANZA_FIXTURE_AUTHORIZABLE_CLIENT_REFERENCE'),
            ]);
            $this->fail('expected a conflict ApiException');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('conflict', $e->kind);
            $this->assertSame('duplicate_client_reference', $e->type);
            $this->assertSame('client_reference', $e->param);
            $this->assertSame(FixtureIds::id('MANZA_FIXTURE_AUTHORIZABLE_DRAFT_ID'), $e->paymentId);
        }
    }

    public function testPaymentsGet(): void
    {
        $client = $this->replayClient('payments/get');

        $got = $client->payments->get(FixtureIds::id('MANZA_FIXTURE_TRANSFER_DRAFT_ID'));

        $this->assertIsString($got->body['id'] ?? null, 'expected string id');
        $this->assertArrayHasKey('status', $got->body);
        $this->assertArrayHasKey('transfer', $got->body);
    }

    public function testPaymentsGetForADraftRefusedAtCreateTime(): void
    {
        $client = $this->replayClient('payments/get_refused');

        $got = $client->payments->get(FixtureIds::id('MANZA_FIXTURE_REFUSED_DRAFT_ID'));

        $this->assertSame(FixtureIds::id('MANZA_FIXTURE_REFUSED_DRAFT_ID'), $got->body['id'] ?? null);
        $this->assertSame('refused', $got->body['authorization']['status'] ?? null);
        $this->assertSame('over_transfer_limit', $got->body['authorization']['refusal_reason'] ?? null);
    }

    public function testPaymentsAuthorizeWithBadSignature(): void
    {
        $client = $this->signatureReplayClient('payments/authorize_bad_signature');

        try {
            $client->payments->authorize(
                FixtureIds::id('MANZA_FIXTURE_BAD_SIGNATURE_DRAFT_ID'),
                FixtureIds::id('MANZA_FIXTURE_BAD_SIGNATURE_AUTHORIZATION_ID'),
                str_repeat('0', 64),
            );
            $this->fail('expected a validation ApiException');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('validation', $e->kind);
            $this->assertSame('invalid_signature', $e->type);
        }
    }

    public function testPaymentsAuthorizeWithTheCreatingKey(): void
    {
        $client = $this->signatureReplayClient('payments/authorize_same_key');

        try {
            $client->payments->authorize(
                FixtureIds::id('MANZA_FIXTURE_AUTHORIZABLE_DRAFT_ID'),
                FixtureIds::id('MANZA_FIXTURE_AUTHORIZABLE_AUTHORIZATION_ID'),
                str_repeat('0', 64),
            );
            $this->fail('expected a forbidden ApiException');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->status);
            $this->assertSame('forbidden', $e->kind);
            $this->assertSame('same_key_forbidden', $e->type);
        }
    }

    public function testPaymentsAuthorize(): void
    {
        $client = $this->signatureReplayClient('payments/authorize');
        $draftId = FixtureIds::id('MANZA_FIXTURE_AUTHORIZABLE_DRAFT_ID');
        $input = TransferAuthorization::signatureInput(
            paymentId: $draftId,
            nonce: FixtureIds::id('MANZA_FIXTURE_AUTHORIZABLE_NONCE'),
            amount: '10.0',
            currencyCode: 'MAD',
            accountId: FixtureIds::id('MANZA_FIXTURE_ACCOUNT_ID'),
            payee: TransferAuthorization::payeeFor(
                externalAccountId: FixtureIds::id('MANZA_FIXTURE_TRUSTED_EXTERNAL_ACCOUNT_ID'),
            ),
            clientReference: FixtureIds::id('MANZA_FIXTURE_AUTHORIZABLE_CLIENT_REFERENCE'),
        );

        $resp = $client->payments->authorize(
            $draftId,
            FixtureIds::id('MANZA_FIXTURE_AUTHORIZABLE_AUTHORIZATION_ID'),
            TransferAuthorization::sign('test-secret-only-used-during-recording', $input),
        );

        $this->assertSame(200, $resp->status);
        $this->assertSame($draftId, $resp->body['id'] ?? null);
        $this->assertSame('authorized', $resp->body['authorization']['status'] ?? null);
    }

    /**
     * The fresh bank account was never trusted; with "Approve transfers to
     * new payees" on, the authorizer approves it and the signature trusts it.
     */
    public function testPaymentsAuthorizeATransferToANewPayee(): void
    {
        $client = $this->signatureReplayClient('payments/authorize_new_payee');
        $draftId = FixtureIds::id('MANZA_FIXTURE_NEW_PAYEE_DRAFT_ID');
        $input = TransferAuthorization::signatureInput(
            paymentId: $draftId,
            nonce: FixtureIds::id('MANZA_FIXTURE_NEW_PAYEE_NONCE'),
            amount: '10.0',
            currencyCode: 'MAD',
            accountId: FixtureIds::id('MANZA_FIXTURE_ACCOUNT_ID'),
            payee: TransferAuthorization::payeeFor(
                externalAccountId: FixtureIds::id('MANZA_FIXTURE_EXTERNAL_ACCOUNT_ID'),
            ),
            clientReference: FixtureIds::id('MANZA_FIXTURE_NEW_PAYEE_CLIENT_REFERENCE'),
        );

        $resp = $client->payments->authorize(
            $draftId,
            FixtureIds::id('MANZA_FIXTURE_NEW_PAYEE_AUTHORIZATION_ID'),
            TransferAuthorization::sign('test-secret-only-used-during-recording', $input),
        );

        $this->assertSame(200, $resp->status);
        $this->assertSame($draftId, $resp->body['id'] ?? null);
        $this->assertSame('authorized', $resp->body['authorization']['status'] ?? null);
    }

    /** Recorded after the new-payee authorize: its signature trusted the payee. */
    public function testTheNewPayeesBankAccountIsTrustedAfterwards(): void
    {
        $client = $this->replayClient('beneficiaries/get_external_account_trusted');

        $resp = $client->beneficiaries->getExternalAccount(
            FixtureIds::id('MANZA_FIXTURE_CREATED_BENEFICIARY_ID'),
            FixtureIds::id('MANZA_FIXTURE_EXTERNAL_ACCOUNT_ID'),
        );

        $this->assertSame(FixtureIds::id('MANZA_FIXTURE_EXTERNAL_ACCOUNT_ID'), $resp->body['id'] ?? null);
        $this->assertTrue($resp->body['trusted'] ?? null);
    }

    public function testPaymentsDecline(): void
    {
        $client = $this->replayClient('payments/decline');

        $resp = $client->payments->decline(
            FixtureIds::id('MANZA_FIXTURE_DECLINABLE_DRAFT_ID'),
            FixtureIds::id('MANZA_FIXTURE_DECLINABLE_AUTHORIZATION_ID'),
            'SDK fixture',
        );

        $this->assertSame(200, $resp->status);
        $this->assertSame(FixtureIds::id('MANZA_FIXTURE_DECLINABLE_AUTHORIZATION_ID'), $resp->body['id'] ?? null);
        $this->assertSame('declined', $resp->body['status'] ?? null);
        $this->assertIsString($resp->body['declined_at'] ?? null);
    }

    public function testBeneficiaries(): void
    {
        $client = $this->replayClient('beneficiaries/list', 'beneficiaries/get');

        $page = $client->beneficiaries->list();
        $this->assertNotEmpty($page->data, 'expected at least one beneficiary');
        $this->assertIsArray(
            $page->data[0]['external_accounts'] ?? null,
            'expected embedded external_accounts',
        );

        $resp = $client->beneficiaries->get(FixtureIds::id('MANZA_FIXTURE_BENEFICIARY_ID'));
        $this->assertIsString($resp->body['id'] ?? null, 'expected string id');
        $this->assertIsArray($resp->body['external_accounts'] ?? null);
    }

    public function testBeneficiariesCreate(): void
    {
        $client = $this->replayClient('beneficiaries/create');

        $resp = $client->beneficiaries->create([
            'beneficiary_type' => 'business',
            'company_name' => 'Manza Fixture Beneficiary spec',
            'email' => 'fixture-beneficiary-spec@example.com',
        ]);

        $this->assertSame(201, $resp->status);
        $this->assertSame('business', $resp->body['beneficiary_type'] ?? null);
        $this->assertSame([], $resp->body['external_accounts'] ?? null);
    }

    public function testBeneficiariesListExternalAccounts(): void
    {
        $client = $this->replayClient('beneficiaries/list_external_accounts');

        $page = $client->beneficiaries->listExternalAccounts(
            FixtureIds::id('MANZA_FIXTURE_CREATED_BENEFICIARY_ID'),
        );

        $this->assertInstanceOf(Page::class, $page);
        $this->assertSame(FixtureIds::id('MANZA_FIXTURE_EXTERNAL_ACCOUNT_ID'), $page->data[0]['id'] ?? null);
        $this->assertIsString($page->data[0]['account_number'] ?? null);
        $this->assertFalse($page->hasMore);
        $this->assertNull($page->next());
    }

    public function testBeneficiariesGetExternalAccount(): void
    {
        $client = $this->replayClient('beneficiaries/get_external_account');

        $resp = $client->beneficiaries->getExternalAccount(
            FixtureIds::id('MANZA_FIXTURE_CREATED_BENEFICIARY_ID'),
            FixtureIds::id('MANZA_FIXTURE_EXTERNAL_ACCOUNT_ID'),
        );

        $this->assertSame(FixtureIds::id('MANZA_FIXTURE_EXTERNAL_ACCOUNT_ID'), $resp->body['id'] ?? null);
        $this->assertArrayHasKey('default', $resp->body);
    }

    public function testBeneficiariesCreateExternalAccount(): void
    {
        $client = $this->replayClient('beneficiaries/create_external_account');

        $resp = $client->beneficiaries->createExternalAccount(
            FixtureIds::id('MANZA_FIXTURE_CREATED_BENEFICIARY_ID'),
            [
                'account_number' => FixtureIds::id('MANZA_FIXTURE_NEW_ACCOUNT_NUMBER'),
                'name' => 'Fixture Secondary Account',
            ],
        );

        $this->assertSame(201, $resp->status);
        $this->assertSame('Fixture Secondary Account', $resp->body['name'] ?? null);
        $this->assertFalse($resp->body['default'] ?? null);
    }

    public function testPayeeTrustRequestsCreate(): void
    {
        $client = $this->replayClient('payee_trust_requests/create');

        $resp = $client->payeeTrustRequests->create([FixtureIds::id('MANZA_FIXTURE_EXTERNAL_ACCOUNT_ID')]);

        $this->assertSame(201, $resp->status);
        $this->assertSame('pending', $resp->body['status'] ?? null);
        $this->assertSame(
            [FixtureIds::id('MANZA_FIXTURE_EXTERNAL_ACCOUNT_ID')],
            $resp->body['external_account_ids'] ?? null,
        );
    }

    public function testPayeeTrustRequestsGet(): void
    {
        $client = $this->replayClient('payee_trust_requests/get');

        $resp = $client->payeeTrustRequests->get(FixtureIds::id('MANZA_FIXTURE_PAYEE_TRUST_REQUEST_ID'));

        $this->assertSame(FixtureIds::id('MANZA_FIXTURE_PAYEE_TRUST_REQUEST_ID'), $resp->body['id'] ?? null);
        $this->assertArrayHasKey('resolved_at', $resp->body);
        $this->assertNull($resp->body['resolved_at']);
    }

    public function testTransferAuthorizerGet(): void
    {
        $client = $this->replayClient('transfer_authorizer/get');

        $resp = $client->transferAuthorizer->get();

        $this->assertSame(200, $resp->status);
        $this->assertSame('active', $resp->body['status'] ?? null);
        $this->assertArrayHasKey('suspension_reason', $resp->body);
        $this->assertNull($resp->body['suspension_reason']);
        $this->assertSame('MAD', $resp->body['currency_code'] ?? null);
        foreach (['per_transfer_limit', 'daily_limit', 'daily_authorized_total', 'secret_expires_at', 'webhook_endpoint_id', 'enrolled_at'] as $key) {
            $this->assertArrayHasKey($key, $resp->body);
        }
    }
}
