<?php

declare(strict_types=1);

namespace Manza\Resources;

use Manza\Client;
use Manza\Page;
use Manza\Response;

/**
 * Accounts and their transactions.
 */
final class Accounts extends AbstractResource
{
    /**
     * GET /api/accounts
     */
    public function list(
        ?string $status = null,
        ?string $currencyCode = null,
        ?int $limit = null,
        ?string $cursor = null,
    ): Page {
        $filters = self::compact(['status' => $status, 'currency_code' => $currencyCode]);

        return $this->client->listPage('api/accounts', $filters, $limit, $cursor);
    }

    /**
     * GET /api/accounts/:id
     */
    public function get(string $id): Response
    {
        return $this->client->request('GET', Client::encodePath('api/accounts', $id));
    }

    /**
     * GET /api/accounts/:account_id/transactions
     *
     * @param string|null $postedAfter ISO-8601
     * @param string|null $postedBefore ISO-8601
     */
    public function listTransactions(
        string $accountId,
        ?string $operation = null,
        ?string $postedAfter = null,
        ?string $postedBefore = null,
        ?int $limit = null,
        ?string $cursor = null,
    ): Page {
        $filters = self::compact([
            'operation' => $operation,
            'posted_after' => $postedAfter,
            'posted_before' => $postedBefore,
        ]);

        return $this->client->listPage(
            Client::encodePath('api/accounts', $accountId, 'transactions'),
            $filters,
            $limit,
            $cursor,
        );
    }

    /**
     * GET /api/accounts/:account_id/transactions/:id
     */
    public function getTransaction(string $accountId, string $transactionId): Response
    {
        return $this->client->request(
            'GET',
            Client::encodePath('api/accounts', $accountId, 'transactions', $transactionId),
        );
    }
}
