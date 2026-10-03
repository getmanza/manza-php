# manza-php

PHP SDK for the [Manza](https://ma.manza.finance) API.

```bash
composer require manza/manza-php
```

```php
use Manza\Client;

$client = new Client(apiKey: getenv('MANZA_API_KEY'));

// The base URL defaults to https://ma.manza.finance (Morocco). For South
// Africa, point at https://za.manza.finance:
$za = new Client(apiKey: getenv('MANZA_API_KEY'), baseUrl: 'https://za.manza.finance');

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
use Manza\TransferAuthorization;

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

$authorizer = new Client(apiKey: getenv('MANZA_AUTHORIZER_API_KEY'));
$authorizer->transferDrafts->authorize($draft['id'], $webhook['data']['authorization']['id'], $signature);
$authorizer->transferDrafts->decline($draft['id'], $authorizationId, 'Not ours'); // reason is optional
```

A blank signature is refused locally with an `\InvalidArgumentException`, because the API counts a missing one as a failed attempt. A wrong signature throws an `ApiException` of kind `validation` (`type` `invalid_signature`). Five on one challenge send the draft to your in-app approvers; five in a row suspend the authorizer.

## Configuration

| Env var | Meaning |
|---|---|
| `MANZA_API_KEY` | API key, used when `apiKey` is not passed |
| `MANZA_BASE_URL` | Base URL (default `https://ma.manza.finance`) |
| `MANZA_API_VERSION` | Pins the `Manza-Version` request header |

The legacy `ZAZU_API_KEY`, `ZAZU_BASE_URL` and `ZAZU_API_VERSION` are still read when the `MANZA_*` name is unset, with a one-time `E_USER_DEPRECATED` warning per variable. The fallback stays for all of 1.x.

## Response shape

Response bodies are returned as-is from the API — `snake_case` keys in an
associative array, no typed models. The same shape ships across every Manza
SDK (Ruby, TypeScript, Python, Go, PHP, ...) so the cassette contract is
one-to-one.

## Pagination

List endpoints return a `Manza\Page` with `data`, `hasMore`, and
`nextCursor`; call `next()` to fetch the following page (null when done).
Page size is capped at 100.

## Errors

Non-2xx responses throw `Manza\Exception\ApiException` with `status`,
`kind` (`authentication`, `forbidden`, `not_found`, `validation`,
`conflict`, `rate_limit`, `server`, `api`), the API's `type`/`message`/`param`, the
`requestId`, `retryAfter` for 429s, and `paymentId` for a 409 on a
duplicate `client_reference` (it names the existing draft). 400 and 422
are both `validation`; 409 is `conflict`. Transport failures throw
`Manza\Exception\ConnectionException`; client misconfiguration throws
`Manza\Exception\ConfigurationException`.

## Tests

Tests replay the canonical cassettes recorded by
[manza-ruby](https://github.com/getmanza/manza-ruby), against `https://ma.manza.dev`. The cassettes are
downloaded from the Ruby SDK's release tarball and served from a Guzzle
replay handler. Same interactions, same assertions, every language.

```bash
scripts/fetch-cassettes.sh
composer install
vendor/bin/phpunit
```

## Migrating from `getzazu/zazu-php`

See the migration notes in [CHANGELOG.md](CHANGELOG.md): `composer require manza/manza-php`, `Zazu\` becomes `Manza\`, `ZAZU_*` becomes `MANZA_*`.

## The SDK family

| SDK | Repository | Install |
|---|---|---|
| Ruby (reference implementation, records the cassettes) | [getmanza/manza-ruby](https://github.com/getmanza/manza-ruby) | `gem "manza"` |
| TypeScript / JavaScript | [getmanza/manza-ts](https://github.com/getmanza/manza-ts) | `npm install @getmanza/sdk` |
| Python | [getmanza/manza-python](https://github.com/getmanza/manza-python) | `pip install manza` |
| Go | [getmanza/manza-go](https://github.com/getmanza/manza-go) | `go get github.com/getmanza/manza-go` |
| PHP | [getmanza/manza-php](https://github.com/getmanza/manza-php) (this repo) | `composer require manza/manza-php` |
| Rust | [getmanza/manza-rust](https://github.com/getmanza/manza-rust) | `cargo add manza` |
| Crystal | [getmanza/manza-crystal](https://github.com/getmanza/manza-crystal) | shard `manza` (`github: getmanza/manza-crystal`) |
| Elixir | [getmanza/manza-elixir](https://github.com/getmanza/manza-elixir) | `{:manza, "~> 1.0"}` |
| CLI | [getmanza/cli](https://github.com/getmanza/cli) | `npm install -g @getzazu/cli` or `brew install getzazu/tap/zazu` |

## Releasing

Maintainers: run `bin/release` from a clean, up-to-date `main` (`bin/release --help` for the options).
