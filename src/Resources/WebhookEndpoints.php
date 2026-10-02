<?php

declare(strict_types=1);

namespace Manza\Resources;

use Manza\Client;
use Manza\Page;
use Manza\Response;

/**
 * Webhook endpoint management.
 */
final class WebhookEndpoints extends AbstractResource
{
    /**
     * GET /api/webhook_endpoints
     */
    public function list(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->client->listPage('api/webhook_endpoints', [], $limit, $cursor);
    }

    /**
     * GET /api/webhook_endpoints/:id
     */
    public function get(string $id): Response
    {
        return $this->client->request('GET', Client::encodePath('api/webhook_endpoints', $id));
    }

    /**
     * POST /api/webhook_endpoints
     *
     * @param array<string, mixed> $attributes snake_case keys, exactly what the API accepts
     */
    public function create(array $attributes): Response
    {
        return $this->client->request('POST', 'api/webhook_endpoints', body: $attributes);
    }

    /**
     * PATCH /api/webhook_endpoints/:id
     *
     * Changing `url` on the endpoint enrolled as transfer authorizer is
     * rejected with a validation ApiException (422).
     *
     * @param array<string, mixed> $attributes snake_case keys, exactly what the API accepts
     */
    public function update(string $id, array $attributes): Response
    {
        return $this->client->request('PATCH', Client::encodePath('api/webhook_endpoints', $id), body: $attributes);
    }

    /**
     * DELETE /api/webhook_endpoints/:id
     *
     * A soft delete (still 204). Rejected with a validation ApiException
     * (422) on the enrolled transfer authorizer.
     */
    public function delete(string $id): Response
    {
        return $this->client->request('DELETE', Client::encodePath('api/webhook_endpoints', $id));
    }

    /**
     * POST /api/webhook_endpoints/:id/test
     */
    public function test(string $id): Response
    {
        return $this->client->request('POST', Client::encodePath('api/webhook_endpoints', $id, 'test'));
    }

    /**
     * POST /api/webhook_endpoints/:id/regenerate_secret
     *
     * Rejected with a validation ApiException (422) on the enrolled
     * transfer authorizer.
     */
    public function regenerateSecret(string $id): Response
    {
        return $this->client->request('POST', Client::encodePath('api/webhook_endpoints', $id, 'regenerate_secret'));
    }

    /**
     * POST /api/webhook_endpoints/:id/enable
     */
    public function enable(string $id): Response
    {
        return $this->client->request('POST', Client::encodePath('api/webhook_endpoints', $id, 'enable'));
    }

    /**
     * POST /api/webhook_endpoints/:id/disable
     */
    public function disable(string $id): Response
    {
        return $this->client->request('POST', Client::encodePath('api/webhook_endpoints', $id, 'disable'));
    }
}
