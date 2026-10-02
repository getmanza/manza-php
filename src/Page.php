<?php

declare(strict_types=1);

namespace Manza;

/**
 * One page of a cursor-paginated list endpoint:
 * `{ "data": [...], "has_more": bool, "next_cursor": string|null }`.
 */
final class Page
{
    /** The API's hard page-size cap. */
    public const MAX_PER_PAGE = 100;

    /**
     * @param list<array<string, mixed>> $data
     * @param \Closure(string): Page $fetch
     */
    public function __construct(
        public readonly array $data,
        public readonly bool $hasMore,
        public readonly ?string $nextCursor,
        public readonly Response $response,
        private readonly \Closure $fetch,
    ) {
    }

    /**
     * Fetches the following page, or returns null when this is the last one.
     */
    public function next(): ?self
    {
        if (!$this->hasMore || $this->nextCursor === null || $this->nextCursor === '') {
            return null;
        }

        return ($this->fetch)($this->nextCursor);
    }
}
