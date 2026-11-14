<?php

declare(strict_types=1);

namespace Manza\Resources;

use Manza\Client;
use Manza\Page;
use Manza\Response;

/**
 * Invoices and their lifecycle actions.
 */
final class Invoices extends AbstractResource
{
    /**
     * GET /api/invoices
     *
     * `status`: draft, pending_approval, declined, approved, sent, paid,
     * overdue or cancelled.
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
     * Required: customer_id, account_id (the entity's account the invoice
     * is paid into, from {@see Accounts::list()}; without one the API
     * answers 422 `state_error`, and an account that is not the entity's
     * 422 with `param` "account_id"), issue_date, due_date and items.
     * Optional: currency_code, reference, notes, payment_terms,
     * send_to_email, discounts, delivery_date and generate_payment_link.
     *
     * `delivery_date` is Morocco only and absent from responses in other
     * markets. `tax_rate` must be the issuer's rate or 0 (otherwise 422).
     *
     * `generate_payment_link: true` asks for a card payment link, made from
     * the invoice's account when the invoice is approved; it needs the
     * `payment_links:write` scope and is taken only while payment links are
     * enabled for the entity. A boolean, null (no link) or the strings
     * "true", "false", "1" and "0" are taken; any other value throws a
     * `validation` {@see \Manza\Exception\ApiException} (400) and nothing
     * is created. It replaces the removed `createPaymentLink()`.
     *
     * The response carries `generate_payment_link`, the embedded `account`
     * (`id`, `name`, `currency_code`, and `rib` in Morocco or
     * `account_number` in South Africa) and `payment_link` once one exists.
     * A plan that caps invoices throws
     * {@see \Manza\Exception\PlanLimitReachedException}.
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
     * Takes the create fields, account_id and generate_payment_link included
     * (the same scope and entity rule apply), on an invoice that is still
     * editable.
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
     *
     * Only a `draft`, `pending_approval` or `declined` invoice that has no
     * number yet; any other throws a `validation`
     * {@see \Manza\Exception\ApiException} (`state_error`).
     */
    public function delete(string $id): Response
    {
        return $this->client->request('DELETE', Client::encodePath('api/invoices', $id));
    }
}
