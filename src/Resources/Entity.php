<?php

declare(strict_types=1);

namespace Manza\Resources;

use Manza\Response;

/**
 * The current entity (the tenant the API key belongs to).
 */
final class Entity extends AbstractResource
{
    /**
     * GET /api/entity
     */
    public function get(): Response
    {
        return $this->client->request('GET', 'api/entity');
    }
}
