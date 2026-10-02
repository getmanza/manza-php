<?php

declare(strict_types=1);

namespace Zazu\Resources;

use Zazu\Client;
use Zazu\Response;

/**
 * Requests to trust payees for machine-authorized transfers. The API
 * key can only ask: a member holding payment-authorize permission
 * approves the request in the Zazu app. Status: pending → approved /
 * declined / cancelled. There is no list, update, or delete.
 */
final class PayeeTrustRequests extends AbstractResource
{
    /**
     * POST /api/payee_trust_requests
     *
     * @param list<string> $externalAccountIds at most 100 bank accounts
     */
    public function create(array $externalAccountIds): Response
    {
        return $this->client->request(
            'POST',
            'api/payee_trust_requests',
            body: ['external_account_ids' => $externalAccountIds],
        );
    }

    /**
     * GET /api/payee_trust_requests/:id
     */
    public function get(string $id): Response
    {
        return $this->client->request('GET', Client::encodePath('api/payee_trust_requests', $id));
    }
}
