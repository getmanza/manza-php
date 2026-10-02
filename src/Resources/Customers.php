<?php

declare(strict_types=1);

namespace Zazu\Resources;

use Zazu\Client;
use Zazu\Page;
use Zazu\Response;

/**
 * Individuals or businesses the entity invoices.
 */
final class Customers extends AbstractResource
{
    /**
     * GET /api/customers
     *
     * @param string|null $q Matches company name, person name, email
     */
    public function list(?string $q = null, ?int $limit = null, ?string $cursor = null): Page
    {
        $filters = self::compact(['q' => $q]);

        return $this->client->listPage('api/customers', $filters, $limit, $cursor);
    }

    /**
     * GET /api/customers/:id
     */
    public function get(string $id): Response
    {
        return $this->client->request('GET', Client::encodePath('api/customers', $id));
    }

    /**
     * POST /api/customers
     *
     * Keys: person_name, company_name, email, phone, registration_number,
     * vat_number, billing_address (array with street/city/postal_code/
     * country/country_code). Morocco only: tax_id, ice_number. These keys
     * are absent from responses in other markets.
     *
     * @param array<string, mixed> $attributes snake_case keys, exactly what the API accepts
     */
    public function create(array $attributes): Response
    {
        return $this->client->request('POST', 'api/customers', body: $attributes);
    }

    /**
     * PATCH /api/customers/:id
     *
     * @param array<string, mixed> $attributes snake_case keys, exactly what the API accepts
     */
    public function update(string $id, array $attributes): Response
    {
        return $this->client->request('PATCH', Client::encodePath('api/customers', $id), body: $attributes);
    }

    /**
     * DELETE /api/customers/:id
     */
    public function delete(string $id): Response
    {
        return $this->client->request('DELETE', Client::encodePath('api/customers', $id));
    }
}
