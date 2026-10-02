# zazu-php

PHP SDK for the [Zazu](https://zazu.ma) API.

```bash
composer require getzazu/zazu-php
```

```php
use Zazu\Client;

$client = new Client(apiKey: getenv('ZAZU_API_KEY'));

$entity = $client->entity->get();

$page = $client->accounts->list();
foreach ($page->data as $account) {
    echo $account['id'], ' ', $account['name'], PHP_EOL;
}

// Initiate a transfer — it lands in your workspace's in-app approval
// queue; the API never executes a transfer itself.
$draft = $client->transferDrafts->create([
    'account_id' => $accountId,
    'beneficiary_id' => $beneficiaryId,
    'amount' => '150.00',
    'payment_reference' => 'INV-000042',
]);
```

## Response shape

Response bodies are returned as-is from the API — `snake_case` keys in an
associative array, no typed models. The same shape ships across every Zazu
SDK (Ruby, TypeScript, Python, Go, PHP, ...) so the cassette contract is
one-to-one.

## Pagination

List endpoints return a `Zazu\Page` with `data`, `hasMore`, and
`nextCursor`; call `next()` to fetch the following page (null when done).
Page size is capped at 100.

## Errors

Non-2xx responses throw `Zazu\Exception\ApiException` with `status`,
`kind` (`authentication`, `forbidden`, `not_found`, `validation`,
`rate_limit`, `server`, `api`), the API's `type`/`message`/`param`, the
`requestId`, and `retryAfter` for 429s. Transport failures throw
`Zazu\Exception\ConnectionException`; client misconfiguration throws
`Zazu\Exception\ConfigurationException`.

## Tests

Tests replay the canonical cassettes recorded by
[zazu-ruby](https://github.com/getzazu/zazu-ruby). The cassettes are
downloaded from the Ruby SDK's release tarball and served from a Guzzle
replay handler. Same interactions, same assertions, every language.

```bash
scripts/fetch-cassettes.sh
composer install
vendor/bin/phpunit
```

## The SDK family

- [zazu-ruby](https://github.com/getzazu/zazu-ruby) — reference implementation (records the cassettes)
- [zazu-ts](https://github.com/getzazu/zazu-ts)
- [zazu-python](https://github.com/getzazu/zazu-python)
- [zazu-go](https://github.com/getzazu/zazu-go)
- [cli](https://github.com/getzazu/cli)

## Releasing

Maintainers: run `bin/release` from a clean, up-to-date `main` (`bin/release --help` for the options).
