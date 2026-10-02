<?php

declare(strict_types=1);

namespace Zazu\Resources;

use Zazu\Client;
use Zazu\Page;
use Zazu\Response;

/**
 * Standalone payment links (not attached to an invoice).
 */
final class PaymentLinks extends AbstractResource
{
    /**
     * GET /api/payment_links
     */
    public function list(
        ?string $status = null,
        ?string $linkType = null,
        ?int $limit = null,
        ?string $cursor = null,
    ): Page {
        $filters = self::compact(['status' => $status, 'link_type' => $linkType]);

        return $this->client->listPage('api/payment_links', $filters, $limit, $cursor);
    }

    /**
     * GET /api/payment_links/:id
     */
    public function get(string $id): Response
    {
        return $this->client->request('GET', Client::encodePath('api/payment_links', $id));
    }

    /**
     * POST /api/payment_links
     *
     * Optional billing keys: collect_billing_address, billing_address.
     * Responses carry `settled_at`; status includes `clearing`.
     *
     * @param array<string, mixed> $attributes snake_case keys, exactly what the API accepts
     */
    public function create(array $attributes): Response
    {
        return $this->client->request('POST', 'api/payment_links', body: $attributes);
    }

    /**
     * POST /api/payment_links/:id/cancel
     */
    public function cancel(string $id): Response
    {
        return $this->client->request('POST', Client::encodePath('api/payment_links', $id, 'cancel'));
    }
}
