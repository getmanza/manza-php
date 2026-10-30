<?php

declare(strict_types=1);

namespace Manza\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as Psr7Response;
use PHPUnit\Framework\TestCase;
use Manza\Client;
use Manza\Exception\ApiException;
use Manza\Resources\Payments;
use Manza\Resources\TransferDrafts;

/**
 * Mirror of the "error mapping" and local-validation specs in manza-ruby's
 * client_spec.rb / payments_spec.rb / transfer_drafts_spec.rb, run against a
 * mock handler.
 */
final class ErrorMappingTest extends TestCase
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    /**
     * @param list<Psr7Response> $responses
     */
    private function client(array $responses): Client
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new Client(apiKey: 'k', httpClient: new GuzzleClient(['handler' => $stack]));
    }

    /**
     * @param array<string, mixed> $error
     */
    private function errorResponse(int $status, array $error): Psr7Response
    {
        return new Psr7Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode(['error' => $error], \JSON_THROW_ON_ERROR),
        );
    }

    public function testMaps400ToValidation(): void
    {
        $client = $this->client([
            $this->errorResponse(400, ['message' => 'limit is malformed', 'type' => 'invalid_request_error']),
        ]);

        try {
            $client->entity->get();
            $this->fail('expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->status);
            $this->assertSame('validation', $e->kind);
            $this->assertSame('limit is malformed', $e->getMessage());
            $this->assertSame('invalid_request_error', $e->type);
            $this->assertNull($e->paymentId);
        }
    }

    public function testMaps409ToConflictCarryingPaymentId(): void
    {
        $client = $this->client([
            $this->errorResponse(409, [
                'message' => 'A transfer with this client_reference already exists',
                'type' => 'duplicate_client_reference',
                'param' => 'client_reference',
                'payment_id' => 'pay_1',
            ]),
        ]);

        try {
            $client->entity->get();
            $this->fail('expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('conflict', $e->kind);
            $this->assertSame('duplicate_client_reference', $e->type);
            $this->assertSame('client_reference', $e->param);
            $this->assertSame('pay_1', $e->paymentId);
        }
    }

    public function testLeavesPaymentIdNullWhen409OmitsIt(): void
    {
        $client = $this->client([$this->errorResponse(409, ['message' => 'Conflict'])]);

        try {
            $client->entity->get();
            $this->fail('expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame('conflict', $e->kind);
            $this->assertNull($e->paymentId);
        }
    }

    public function testAuthorizeRefusesABlankSignatureBeforeAnyHttpCall(): void
    {
        $client = $this->client([]);

        foreach (['', '   '] as $blank) {
            try {
                $client->payments->authorize('draft', 'auth', $blank);
                $this->fail('expected InvalidArgumentException');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('signature', $e->getMessage());
            }
        }
        $this->assertCount(0, $this->history, 'no request may be sent');
    }

    public function testDeclineOmitsReasonWhenAbsent(): void
    {
        $client = $this->client([new Psr7Response(200, [], '{}'), new Psr7Response(200, [], '{}')]);

        $client->payments->decline('draft', 'auth');
        $client->payments->decline('draft', 'auth', 'Not ours');

        $this->assertSame('{"authorization_id":"auth"}', (string) $this->history[0]['request']->getBody());
        $this->assertSame(
            '{"authorization_id":"auth","reason":"Not ours"}',
            (string) $this->history[1]['request']->getBody(),
        );
        $this->assertSame('/api/payments/draft/decline', $this->history[0]['request']->getUri()->getPath());
    }

    public function testTransferAuthorizerGetWithNoneEnrolledIsNotFound(): void
    {
        $client = $this->client([$this->errorResponse(404, ['message' => 'Not found', 'type' => 'not_found_error'])]);

        try {
            $client->transferAuthorizer->get();
            $this->fail('expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame('not_found', $e->kind);
        }
        $this->assertSame('/api/transfer_authorizer', $this->history[0]['request']->getUri()->getPath());
    }

    /** The deprecated alias stays for all of 1.x and calls the canonical paths. */
    public function testTransferDraftsIsADeprecatedAliasOfPayments(): void
    {
        $ok = static fn (): Psr7Response => new Psr7Response(200, [], '{}');
        $client = $this->client([$ok(), $ok(), $ok(), $ok()]);

        $this->assertInstanceOf(TransferDrafts::class, $client->transferDrafts);
        $this->assertInstanceOf(Payments::class, $client->transferDrafts);

        $client->transferDrafts->create(['amount' => '1.00']);
        $client->transferDrafts->get('draft');
        $client->transferDrafts->authorize('draft', 'auth', 'sig');
        $client->transferDrafts->decline('draft', 'auth');

        $this->assertSame(
            [
                'POST /api/payments',
                'GET /api/payments/draft',
                'POST /api/payments/draft/authorize',
                'POST /api/payments/draft/decline',
            ],
            array_map(
                static fn (array $entry): string => $entry['request']->getMethod() . ' ' . $entry['request']->getUri()->getPath(),
                $this->history,
            ),
        );
    }
}
