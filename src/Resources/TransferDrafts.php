<?php

declare(strict_types=1);

namespace Zazu\Resources;

use Zazu\Client;
use Zazu\Response;

/**
 * API-initiated transfers. Creating a draft never executes a transfer
 * by itself. A draft inside the entity's machine-authorization envelope
 * (trusted payee, within limits) is sent to the enrolled transfer
 * authorizer as a `payment.authorization_requested` webhook; answer it
 * with {@see TransferDrafts::authorize()} or {@see TransferDrafts::decline()},
 * using an API key other than the one that created the draft. Every
 * other draft goes to the in-app approval flow, where a manager or
 * legal representative approves it. Poll {@see TransferDrafts::get()}
 * (status: requested → processing → completed / failed) or subscribe to
 * the `transfer.executed` webhook to follow execution.
 */
final class TransferDrafts extends AbstractResource
{
    /**
     * POST /api/transfer_drafts
     *
     * Required: account_id, amount, and exactly one of beneficiary_id
     * (external transfer) or destination_account_id (own-account move).
     * Optional: external_account_id, currency_code, payment_reference,
     * internal_notes, client_reference (unique per entity, at most 128
     * characters; a duplicate throws an {@see \Zazu\Exception\ApiException}
     * of kind `conflict` whose `paymentId` names the existing draft).
     *
     * @param array<string, mixed> $attributes snake_case keys, exactly what the API accepts
     */
    public function create(array $attributes): Response
    {
        return $this->client->request('POST', 'api/transfer_drafts', body: $attributes);
    }

    /**
     * GET /api/transfer_drafts/:id
     */
    public function get(string $id): Response
    {
        return $this->client->request('GET', Client::encodePath('api/transfer_drafts', $id));
    }

    /**
     * POST /api/transfer_drafts/:id/authorize
     *
     * Executes the draft. `$authorizationId` comes from the
     * `payment.authorization_requested` webhook; build `$signature` with
     * {@see \Zazu\TransferAuthorization}. Requires the `transfers:authorize`
     * scope on a key other than the draft's creator (otherwise 403
     * `same_key_forbidden`). A blank signature is refused locally: the
     * API counts it as a failed attempt, and five fail the challenge.
     *
     * @throws \InvalidArgumentException when $signature is blank
     */
    public function authorize(string $id, string $authorizationId, string $signature): Response
    {
        if (trim($signature) === '') {
            throw new \InvalidArgumentException('signature cannot be blank');
        }

        return $this->client->request(
            'POST',
            Client::encodePath('api/transfer_drafts', $id, 'authorize'),
            body: ['authorization_id' => $authorizationId, 'signature' => $signature],
        );
    }

    /**
     * POST /api/transfer_drafts/:id/decline
     *
     * Declines the challenge and deletes the draft. Returns the
     * authorization (`status: "declined"`). `$reason` is omitted from
     * the request when null.
     */
    public function decline(string $id, string $authorizationId, ?string $reason = null): Response
    {
        return $this->client->request(
            'POST',
            Client::encodePath('api/transfer_drafts', $id, 'decline'),
            body: self::compact(['authorization_id' => $authorizationId, 'reason' => $reason]),
        );
    }
}
