<?php

declare(strict_types=1);

namespace Manza;

/**
 * A successful (2xx) API response.
 *
 * The body is returned as-is from the API — snake_case keys in an
 * associative array, no typed models. The same shape ships across every
 * Manza SDK (Ruby, TypeScript, Python, Go, PHP, ...) so the cassette
 * contract is one-to-one.
 */
final class Response
{
    /**
     * @param array<string, mixed> $body Decoded JSON body (empty array when the body is empty or not JSON)
     * @param string $raw The raw response body bytes
     */
    public function __construct(
        public readonly int $status,
        public readonly ?string $requestId,
        public readonly array $body,
        public readonly string $raw,
    ) {
    }
}
