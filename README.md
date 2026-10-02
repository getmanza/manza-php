# zazu-php

PHP SDK for the [Manza](https://ma.manza.finance) API.

```bash
composer require getzazu/zazu-php
```

```php
use Zazu\Client;

$client = new Client(apiKey: getenv('ZAZU_API_KEY'));

// The base URL defaults to https://ma.manza.finance (Morocco). For South
// Africa, point at https://za.manza.finance:
$za = new Client(apiKey: getenv('ZAZU_API_KEY'), baseUrl: 'https://za.manza.finance');

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
    'client_reference' => 'po_1042', // unique per entity; a duplicate is a `conflict` ApiException
]);

// Beneficiaries and their bank accounts.
$client->beneficiaries->create(['beneficiary_type' => 'business', 'company_name' => 'Acme Supplies', 'email' => 'ap@acme.com']);
$client->beneficiaries->listExternalAccounts($beneficiaryId);
$client->beneficiaries->getExternalAccount($beneficiaryId, $externalAccountId);
$client->beneficiaries->createExternalAccount($beneficiaryId, ['account_number' => '007780...', 'name' => 'Main account']);

// Ask for a payee to be trusted for machine-authorized transfers.
$client->payeeTrustRequests->create([$externalAccountId]);
$client->payeeTrustRequests->get($trustRequestId);
```

## Machine-authorized transfers

A draft inside your entity's authorization envelope (trusted payee, within limits) is sent to your enrolled authorizer endpoint as a `payment.authorization_requested` webhook carrying an `authorization.id` and a one-time `nonce`. Sign the draft from **your own record** of it with the endpoint's signing secret, and authorize it with a **different API key** from the one that created it (the creating key gets 403 `same_key_forbidden`):

```php
use Zazu\TransferAuthorization;

$input = TransferAuthorization::signatureInput(
    paymentId: $draft['id'],
    nonce: $webhook['data']['authorization']['nonce'],
    amount: $draft['amount'],                // the API's decimal string, e.g. "2500.0"
    currencyCode: $draft['currency_code'],
    accountId: $draft['account_id'],
    payee: TransferAuthorization::payeeFor(externalAccountId: $draft['external_account_id']),
    clientReference: $draft['client_reference'],
);
$signature = TransferAuthorization::sign($signingSecret, $input);

$authorizer = new Client(apiKey: getenv('ZAZU_AUTHORIZER_API_KEY'));
$authorizer->transferDrafts->authorize($draft['id'], $webhook['data']['authorization']['id'], $signature);
$authorizer->transferDrafts->decline($draft['id'], $authorizationId, 'Not ours'); // reason is optional
```

A blank signature is refused locally with an `\InvalidArgumentException`, because the API counts a missing one as a failed attempt. A wrong signature throws an `ApiException` of kind `validation` (`type` `invalid_signature`). Five on one challenge send the draft to your in-app approvers; five in a row suspend the authorizer.

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
`conflict`, `rate_limit`, `server`, `api`), the API's `type`/`message`/`param`, the
`requestId`, `retryAfter` for 429s, and `paymentId` for a 409 on a
duplicate `client_reference` (it names the existing draft). 400 and 422
are both `validation`; 409 is `conflict`. Transport failures throw
`Zazu\Exception\ConnectionException`; client misconfiguration throws
`Zazu\Exception\ConfigurationException`.

## Tests

Tests replay the canonical cassettes recorded by
[zazu-ruby](https://github.com/getzazu/zazu-ruby), against `https://ma.manza.dev`. The cassettes are
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
