<?php

declare(strict_types=1);

namespace Manza\Resources;

use Manza\Client;
use Manza\Response;

/**
 * API-initiated transfers, called payments (formerly transfer drafts;
 * `/api/transfer_drafts` stays a permanent server-side alias). Creating
 * a draft never executes a transfer by itself. A draft inside the
 * entity's machine-authorization envelope (trusted payee, within limits)
 * is sent to the enrolled transfer authorizer as a
 * `payment.authorization_requested` webhook; answer it with
 * {@see Payments::authorize()} or {@see Payments::decline()}, using an
 * API key other than the one that created the draft. Every other draft
 * goes to the in-app approval flow, where a manager or legal
 * representative approves it. Poll {@see Payments::get()} (status:
 * requested → processing → completed / failed) or subscribe to the
 * `transfer.executed` webhook to follow execution.
 *
 * A draft's `authorization` says which way it went: `pending` (a
 * challenge was sent), `authorized`, `declined` (the draft is deleted),
 * or one of two outcomes that send the draft to the in-app approvers,
 * each with a `refusal_reason`:
 *
 * - `refused`: it did not qualify, at create time (no webhook is sent)
 *   or again when the challenge was answered: `untrusted_payee`,
 *   `unrestricted_api_key`, `over_new_payee_limit`,
 *   `over_transfer_limit`, `over_daily_limit`, `currency_mismatch`,
 *   `suspended` or `secret_expired`.
 * - `expired` / `superseded` / `failed`: a challenge was sent and closed
 *   without an authorize: `expired` (no answer within the hour),
 *   `stale` (the draft, its payee's bank details or the endpoint changed
 *   while it was open) or `signature_failed` (five invalid signatures).
 *   One exception: the in-app approval that finalizes the draft while
 *   its challenge is open supersedes it (`stale`), and the draft
 *   executes or is scheduled; the draft's own `status` shows which.
 *
 * `authorization` is null when no authorizer was enrolled.
 *
 * With "Approve transfers to new payees" on (enrolment, in the app), a
 * draft to bank details the entity never trusted is challenged too, up
 * to the new-payee limit, when the creating key has an IP allowlist;
 * authorizing it also trusts the payee.
 *
 * Not final only so the deprecated {@see TransferDrafts} alias can
 * extend it; don't subclass it yourself.
 */
class Payments extends AbstractResource
{
    /**
     * POST /api/payments
     *
     * Required: account_id, amount, and exactly one of beneficiary_id
     * (external transfer) or destination_account_id (own-account move).
     * Optional: external_account_id, currency_code, payment_reference,
     * internal_notes, client_reference (unique per entity, at most 128
     * characters; a duplicate throws an {@see \Manza\Exception\ApiException}
     * of kind `conflict` whose `paymentId` names the existing draft).
     *
     * @param array<string, mixed> $attributes snake_case keys, exactly what the API accepts
     */
    public function create(array $attributes): Response
    {
        return $this->client->request('POST', 'api/payments', body: $attributes);
    }

    /**
     * GET /api/payments/:id
     */
    public function get(string $id): Response
    {
        return $this->client->request('GET', Client::encodePath('api/payments', $id));
    }

    /**
     * POST /api/payments/:id/authorize
     *
     * Executes the draft. `$authorizationId` comes from the
     * `payment.authorization_requested` webhook; build `$signature` with
     * {@see \Manza\TransferAuthorization}. Requires the `transfers:authorize`
     * scope on a key other than the draft's creator (otherwise 403
     * `same_key_forbidden`), bound to an IP allowlist (otherwise 403
     * `unrestricted_api_key`). A 409 `conflict` with `type`
     * `authorization_busy` means a bank-detail edit, trust or trust
     * revocation on the payee was in flight: retry. A blank signature is
     * refused locally and never sent: the API would count it as a failed
     * attempt. Five invalid signatures fail the challenge.
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
            Client::encodePath('api/payments', $id, 'authorize'),
            body: ['authorization_id' => $authorizationId, 'signature' => $signature],
        );
    }

    /**
     * POST /api/payments/:id/decline
     *
     * Declines the challenge and deletes the draft. Returns the
     * authorization (`status: "declined"`, `declined_at`, and a null
     * `refusal_reason`). `$reason` is omitted from the request when null.
     */
    public function decline(string $id, string $authorizationId, ?string $reason = null): Response
    {
        return $this->client->request(
            'POST',
            Client::encodePath('api/payments', $id, 'decline'),
            body: self::compact(['authorization_id' => $authorizationId, 'reason' => $reason]),
        );
    }
}
