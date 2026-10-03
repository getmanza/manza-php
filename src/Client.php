<?php

declare(strict_types=1);

namespace Manza;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\RequestOptions;
use Manza\Exception\ApiException;
use Manza\Exception\ConfigurationException;
use Manza\Exception\ConnectionException;

/**
 * The SDK entry point. Resources hang off it as readonly properties.
 *
 *     $client = new \Manza\Client(apiKey: 'sk_live_...');
 *     $page = $client->accounts->list();
 *
 * Response bodies are returned as-is from the API — snake_case keys in
 * associative arrays, no typed models. The same shape ships across every
 * Manza SDK (Ruby, TypeScript, Python, Go, PHP, ...) so the cassette
 * contract is one-to-one.
 */
final class Client
{
    /** The SDK version, sent in the User-Agent header. */
    public const VERSION = '0.3.0';

    /** Production, Morocco. South Africa is https://za.manza.finance. */
    public const DEFAULT_BASE_URL = 'https://ma.manza.finance';
    public const DEFAULT_TIMEOUT = 30.0;

    public readonly Resources\Accounts $accounts;
    public readonly Resources\Beneficiaries $beneficiaries;
    public readonly Resources\CheckoutSessions $checkoutSessions;
    public readonly Resources\Customers $customers;
    public readonly Resources\Entity $entity;
    public readonly Resources\Invoices $invoices;
    public readonly Resources\PayeeTrustRequests $payeeTrustRequests;
    public readonly Resources\PaymentLinks $paymentLinks;
    public readonly Resources\TransferDrafts $transferDrafts;
    public readonly Resources\WebhookEndpoints $webhookEndpoints;

    /** @var array<string, true> */
    private static array $warned = [];

    private readonly string $apiKey;
    private readonly string $baseUrl;
    private readonly ?string $apiVersion;
    private readonly ClientInterface $httpClient;

    /**
     * @param string|null $apiKey API key (default: the MANZA_API_KEY env var). Required.
     * @param string|null $baseUrl API base URL (default: MANZA_BASE_URL or https://ma.manza.finance;
     *   use https://za.manza.finance for South Africa)
     * @param string|null $apiVersion Pins the Manza-Version request header (default: MANZA_API_VERSION)
     * @param float $timeout Request timeout in seconds (ignored when $httpClient is supplied)
     * @param ClientInterface|null $httpClient Swaps the underlying Guzzle client
     *
     * @throws ConfigurationException when no API key is available
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?string $apiVersion = null,
        float $timeout = self::DEFAULT_TIMEOUT,
        ?ClientInterface $httpClient = null,
    ) {
        $apiKey ??= self::env('API_KEY');
        if ($apiKey === null || $apiKey === '') {
            throw new ConfigurationException('Missing API key: pass $apiKey or set MANZA_API_KEY.');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl ?? self::env('BASE_URL') ?? self::DEFAULT_BASE_URL, '/');
        $this->apiVersion = $apiVersion ?? self::env('API_VERSION');
        $this->httpClient = $httpClient ?? new GuzzleClient([RequestOptions::TIMEOUT => $timeout]);

        $this->accounts = new Resources\Accounts($this);
        $this->beneficiaries = new Resources\Beneficiaries($this);
        $this->checkoutSessions = new Resources\CheckoutSessions($this);
        $this->customers = new Resources\Customers($this);
        $this->entity = new Resources\Entity($this);
        $this->invoices = new Resources\Invoices($this);
        $this->payeeTrustRequests = new Resources\PayeeTrustRequests($this);
        $this->paymentLinks = new Resources\PaymentLinks($this);
        $this->transferDrafts = new Resources\TransferDrafts($this);
        $this->webhookEndpoints = new Resources\WebhookEndpoints($this);
    }

    /**
     * Performs an HTTP request against the API.
     *
     * Non-2xx responses are thrown as {@see ApiException}; transport
     * failures as {@see ConnectionException}. $body (when non-null) is
     * JSON-encoded.
     *
     * @param array<string, mixed>|null $query
     * @param array<string, mixed>|null $body
     *
     * @throws ApiException
     * @throws ConnectionException
     */
    public function request(string $method, string $path, ?array $query = null, ?array $body = null): Response
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        if ($query !== null && $query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'User-Agent' => 'manza-php/' . self::VERSION,
            'Accept' => 'application/json',
        ];
        if ($this->apiVersion !== null && $this->apiVersion !== '') {
            $headers['Manza-Version'] = $this->apiVersion;
        }

        $options = [
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::ALLOW_REDIRECTS => false,
        ];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $options[RequestOptions::BODY] = json_encode($body, \JSON_THROW_ON_ERROR);
        }
        $options[RequestOptions::HEADERS] = $headers;

        try {
            $raw = $this->httpClient->request($method, $url, $options);
        } catch (TransferException $e) {
            throw new ConnectionException($e->getMessage(), previous: $e);
        }

        $contents = (string) $raw->getBody();
        $parsed = [];
        if ($contents !== '') {
            // Non-JSON bodies stay raw; $parsed stays empty.
            $decoded = json_decode($contents, true);
            if (\is_array($decoded)) {
                $parsed = $decoded;
            }
        }

        $status = $raw->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw ApiException::fromResponse($raw, $parsed);
        }

        $requestId = $raw->getHeaderLine('X-Request-Id');

        return new Response(
            status: $status,
            requestId: $requestId !== '' ? $requestId : null,
            body: $parsed,
            raw: $contents,
        );
    }

    /**
     * Builds a paginated list. $filters is everything besides the shared
     * cursor-pagination inputs. A null $limit means {@see Page::MAX_PER_PAGE}.
     *
     * @param array<string, mixed> $filters
     *
     * @throws \InvalidArgumentException when $limit is out of the 1..100 range
     */
    public function listPage(string $path, array $filters = [], ?int $limit = null, ?string $cursor = null): Page
    {
        $limit ??= Page::MAX_PER_PAGE;
        if ($limit < 1 || $limit > Page::MAX_PER_PAGE) {
            throw new \InvalidArgumentException(
                sprintf('limit must be between 1 and %d (got %d)', Page::MAX_PER_PAGE, $limit),
            );
        }

        $fetch = function (?string $cursor) use (&$fetch, $path, $filters, $limit): Page {
            $query = $filters;
            $query['limit'] = $limit;
            if ($cursor !== null && $cursor !== '') {
                $query['cursor'] = $cursor;
            }

            $response = $this->request('GET', $path, $query);

            $data = [];
            $rows = $response->body['data'] ?? null;
            if (\is_array($rows)) {
                foreach ($rows as $row) {
                    if (\is_array($row)) {
                        $data[] = $row;
                    }
                }
            }

            $nextCursor = $response->body['next_cursor'] ?? null;

            return new Page(
                data: $data,
                hasMore: (bool) ($response->body['has_more'] ?? false),
                nextCursor: \is_string($nextCursor) ? $nextCursor : null,
                response: $response,
                fetch: $fetch,
            );
        };

        return $fetch($cursor);
    }

    /**
     * Builds a request path by joining a literal base path with one or more
     * dynamic segments. Each dynamic segment is percent-encoded so an ID
     * containing `/` or other special characters cannot escape the intended
     * path.
     *
     * @throws \InvalidArgumentException when a segment is blank — an empty
     *   segment would silently turn `/things/:id` into `/things/`, which on
     *   most APIs redispatches to the list endpoint
     */
    public static function encodePath(string $base, string ...$segments): string
    {
        $parts = [$base];
        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new \InvalidArgumentException('path segment cannot be blank');
            }
            $parts[] = rawurlencode($segment);
        }

        return implode('/', $parts);
    }

    /**
     * Reads MANZA_<name>, falling back to the legacy ZAZU_<name> with a
     * one-time (per variable) E_USER_DEPRECATED warning. The fallback
     * stays for all of 1.x.
     */
    private static function env(string $name): ?string
    {
        $value = getenv('MANZA_' . $name);
        if ($value !== false) {
            return $value === '' ? null : $value;
        }

        $legacy = getenv('ZAZU_' . $name);
        if ($legacy === false || $legacy === '') {
            return null;
        }

        if (!isset(self::$warned[$name])) {
            self::$warned[$name] = true;
            trigger_error(
                sprintf('manza-php: ZAZU_%1$s is deprecated; use MANZA_%1$s instead.', $name),
                \E_USER_DEPRECATED,
            );
        }

        return $legacy;
    }

    /**
     * Forgets which legacy ZAZU_* variables already warned. For tests.
     *
     * @internal
     */
    public static function resetDeprecationWarnings(): void
    {
        self::$warned = [];
    }
}
