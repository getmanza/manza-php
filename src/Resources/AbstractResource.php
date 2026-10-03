<?php

declare(strict_types=1);

namespace Manza\Resources;

use Manza\Client;

/**
 * Shared scaffolding for every resource. Carries a back-reference to the
 * client whose HTTP helpers the resources delegate to.
 */
abstract class AbstractResource
{
    public function __construct(protected readonly Client $client)
    {
    }

    /**
     * Drops null values so optional filters never reach the query string.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    protected static function compact(array $filters): array
    {
        return array_filter($filters, static fn (mixed $value): bool => $value !== null);
    }
}
