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

/**
 * Mirror of the "error mapping" and local-validation specs in manza-ruby's
 * client_spec.rb / transfer_drafts_spec.rb, run against a mock handler.
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
                $client->transferDrafts->authorize('draft', 'auth', $blank);
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

        $client->transferDrafts->decline('draft', 'auth');
        $client->transferDrafts->decline('draft', 'auth', 'Not ours');

        $this->assertSame('{"authorization_id":"auth"}', (string) $this->history[0]['request']->getBody());
        $this->assertSame(
            '{"authorization_id":"auth","reason":"Not ours"}',
            (string) $this->history[1]['request']->getBody(),
        );
        $this->assertSame('/api/transfer_drafts/draft/decline', $this->history[0]['request']->getUri()->getPath());
    }
}
