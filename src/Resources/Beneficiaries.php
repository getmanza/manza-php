<?php

declare(strict_types=1);

namespace Manza\Resources;

use Manza\Client;
use Manza\Page;
use Manza\Response;

/**
 * Saved transfer recipients. Each beneficiary embeds its bank accounts;
 * the one flagged `default` is used when a transfer names only the
 * beneficiary_id. There is no update or delete via the API.
 */
final class Beneficiaries extends AbstractResource
{
    /**
     * GET /api/beneficiaries
     */
    public function list(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->client->listPage('api/beneficiaries', [], $limit, $cursor);
    }

    /**
     * GET /api/beneficiaries/:id
     */
    public function get(string $id): Response
    {
        return $this->client->request('GET', Client::encodePath('api/beneficiaries', $id));
    }

    /**
     * POST /api/beneficiaries
     *
     * Keys: beneficiary_type ("individual" | "business"; inferred from
     * person_name / company_name when omitted), person_name,
     * company_name, email, phone_number. Values must be strings. Shares a
     * 10/minute limit with {@see Beneficiaries::createExternalAccount()}.
     *
     * @param array<string, mixed> $attributes snake_case keys, exactly what the API accepts
     */
    public function create(array $attributes): Response
    {
        return $this->client->request('POST', 'api/beneficiaries', body: $attributes);
    }

    /**
     * GET /api/beneficiaries/:beneficiary_id/external_accounts
     */
    public function listExternalAccounts(string $beneficiaryId, ?int $limit = null, ?string $cursor = null): Page
    {
        return $this->client->listPage(
            Client::encodePath('api/beneficiaries', $beneficiaryId, 'external_accounts'),
            [],
            $limit,
            $cursor,
        );
    }

    /**
     * GET /api/beneficiaries/:beneficiary_id/external_accounts/:id
     */
    public function getExternalAccount(string $beneficiaryId, string $id): Response
    {
        return $this->client->request(
            'GET',
            Client::encodePath('api/beneficiaries', $beneficiaryId, 'external_accounts', $id),
        );
    }

    /**
     * POST /api/beneficiaries/:beneficiary_id/external_accounts
     *
     * Required: account_number. Optional: name, country_code,
     * currency_code, account_type ("bank" only), bank_identifier
     * (required in ZA, rejected in MA, where it is derived from the RIB).
     *
     * @param array<string, mixed> $attributes snake_case keys, exactly what the API accepts
     */
    public function createExternalAccount(string $beneficiaryId, array $attributes): Response
    {
        return $this->client->request(
            'POST',
            Client::encodePath('api/beneficiaries', $beneficiaryId, 'external_accounts'),
            body: $attributes,
        );
    }
}
