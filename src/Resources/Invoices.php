<?php

declare(strict_types=1);

namespace Zazu\Resources;

use Zazu\Client;
use Zazu\Page;
use Zazu\Response;

/**
 * Invoices and their lifecycle actions.
 */
final class Invoices extends AbstractResource
{
    /**
     * GET /api/invoices
     */
    public function list(
        ?string $status = null,
        ?string $customerId = null,
        ?int $limit = null,
        ?string $cursor = null,
    ): Page {
        $filters = self::compact(['status' => $status, 'customer_id' => $customerId]);

        return $this->client->listPage('api/invoices', $filters, $limit, $cursor);
    }

    /**
     * GET /api/invoices/:id
     */
    public function get(string $id): Response
    {
        return $this->client->request('GET', Client::encodePath('api/invoices', $id));
    }

    /**
     * POST /api/invoices
     *
     * `delivery_date` is Morocco only and absent from responses in other
     * markets. `tax_rate` must be the issuer's rate or 0 (otherwise 422).
     *
     * @param array<string, mixed> $attributes snake_case keys, exactly what the API accepts
     */
    public function create(array $attributes): Response
    {
        return $this->client->request('POST', 'api/invoices', body: $attributes);
    }

    /**
     * PATCH /api/invoices/:id
     *
     * @param array<string, mixed> $attributes snake_case keys, exactly what the API accepts
     */
    public function update(string $id, array $attributes): Response
    {
        return $this->client->request('PATCH', Client::encodePath('api/invoices', $id), body: $attributes);
    }

    /**
     * POST /api/invoices/:id/send
     */
    public function send(string $id): Response
    {
        return $this->client->request('POST', Client::encodePath('api/invoices', $id, 'send'));
    }

    /**
     * POST /api/invoices/:id/mark_as_paid
     */
    public function markAsPaid(string $id): Response
    {
        return $this->client->request('POST', Client::encodePath('api/invoices', $id, 'mark_as_paid'));
    }

    /**
     * POST /api/invoices/:id/cancel
     */
    public function cancel(string $id): Response
    {
        return $this->client->request('POST', Client::encodePath('api/invoices', $id, 'cancel'));
    }

    /**
     * POST /api/invoices/:id/credit_note
     */
    public function creditNote(string $id): Response
    {
        return $this->client->request('POST', Client::encodePath('api/invoices', $id, 'credit_note'));
    }

    /**
     * DELETE /api/invoices/:id
     */
    public function delete(string $id): Response
    {
        return $this->client->request('DELETE', Client::encodePath('api/invoices', $id));
    }

    /**
     * POST /api/invoices/:invoice_id/payment_link
     */
    public function createPaymentLink(string $invoiceId, string $accountId): Response
    {
        return $this->client->request(
            'POST',
            Client::encodePath('api/invoices', $invoiceId, 'payment_link'),
            body: ['account_id' => $accountId],
        );
    }
}
