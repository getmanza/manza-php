<?php

declare(strict_types=1);

namespace Zazu\Resources;

use Zazu\Client;
use Zazu\Response;

/**
 * One-off hosted checkout sessions. No list, update, or delete; sessions
 * are created and inspected by id. State (`open`, `processing`,
 * `clearing`, `complete`, `expired`) transitions are read-only from the
 * SDK's perspective. Responses carry `settled_at` and the paying
 * `transaction`.
 */
final class CheckoutSessions extends AbstractResource
{
    /**
     * POST /api/checkout_sessions
     *
     * Required attributes: account_id, amount, success_url.
     * Optional: metadata, customer_email, customer_name, cancel_url,
     * description, expires_at, collect_billing_address, billing_address.
     *
     * @param array<string, mixed> $attributes snake_case keys, exactly what the API accepts
     */
    public function create(array $attributes): Response
    {
        return $this->client->request('POST', 'api/checkout_sessions', body: $attributes);
    }

    /**
     * GET /api/checkout_sessions/:id
     */
    public function get(string $id): Response
    {
        return $this->client->request('GET', Client::encodePath('api/checkout_sessions', $id));
    }
}
