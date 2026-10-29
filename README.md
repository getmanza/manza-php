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

// Initiate a transfer. It goes to your enrolled transfer authorizer when it
// is inside your authorization envelope, and to your workspace's in-app
// approvers otherwise; creating it never executes it.
$draft = $client->payments->create([
    'account_id' => $accountId,
    'beneficiary_id' => $beneficiaryId,
    'amount' => '150.00',
    'payment_reference' => 'INV-000042',
    'client_reference' => 'po_1042', // unique per entity; a duplicate is a `conflict` ApiException
]);
$client->payments->get($draft->body['id']); // authorization: status, refusal_reason
// $client->transferDrafts is a deprecated alias of $client->payments (same methods, /api/payments)

// The enrolled transfer authorizer: status, suspension_reason, limits,
// daily_authorized_total. A `not_found` ApiException when none is enrolled.
$client->transferAuthorizer->get();

// Beneficiaries and their bank accounts.
$client->beneficiaries->create(['beneficiary_type' => 'business', 'company_name' => 'Acme Supplies', 'email' => 'ap@acme.com']);
$client->beneficiaries->listExternalAccounts($beneficiaryId);
$client->beneficiaries->getExternalAccount($beneficiaryId, $externalAccountId); // "trusted": true once a trusted payee
$client->beneficiaries->createExternalAccount($beneficiaryId, ['account_number' => '007780...', 'name' => 'Main account']);

// Ask for a payee to be trusted for machine-authorized transfers.
$client->payeeTrustRequests->create([$externalAccountId]);
$client->payeeTrustRequests->get($trustRequestId);
```

## Machine-authorized transfers

A draft inside your entity's authorization envelope (trusted payee, or a new one when you allow it, within limits) is sent to your enrolled authorizer endpoint as a `payment.authorization_requested` webhook carrying an `authorization.id` and a one-time `nonce`. Your system checks it against its own ledger, signs it and authorizes it with a **different API key** from the one that created it. [`examples/transfer_authorizer/`](examples/transfer_authorizer/) runs the whole flow; the [transfer authorization docs](https://ma.manza.finance/docs/api/transfer-authorization) cover every rule.

### Setup, step by step

1. **Feature flags.** Ask support to enable API transfers and transfer authorization (`release_api_webhook_authorization`) on your workspace.
2. **Two API keys.** Key A creates drafts (`transfers:write`; `beneficiaries:request_trust` for step 6; `entity:read`, `accounts:read`, `webhook_endpoints:read` and `beneficiaries:read` for the preflight in step 7). Key B answers the challenges (`transfers:authorize`). The creating key is always refused with 403 `same_key_forbidden`, so keep key B and the signing secret on the system that holds your ledger. Bind key B to an IP allowlist: authorizing from a key without one is refused with 403 `unrestricted_api_key`. Bind key A to one too if you allow new payees (step 6): a draft to a new payee from a key without an allowlist goes to your approvers (`unrestricted_api_key`).
3. **A stable public URL.** Your authorizer needs an HTTPS URL that never changes: an enrolled endpoint's URL is locked. Use a reserved ngrok domain (`ngrok http --url=your-name.ngrok.app 4599`) or a named Cloudflare tunnel. Never a free ngrok URL: it changes on every restart.
4. **Create the webhook endpoint** at that URL:

   ```php
   $client->webhookEndpoints->create(['url' => 'https://your-name.ngrok.app/', 'events' => ['transfer.executed']]);
   ```

5. **Enrol it in the app**: Settings → Developer → Transfer approval. Pick the endpoint, the currency and both limits, confirm with your two-factor code, and copy the signing secret: it is shown once.
6. **Allow new payees, or trust each payee.** Turn on *Approve transfers to new payees* at enrolment (or later, same page, with your two-factor code) and set the *Limit per transfer to a new payee*. Your authorizer may then approve a transfer to bank details your workspace never trusted, up to that limit, and its signature trusts them: the webhook's `payment.payee_trusted` is `false` for such a transfer. Bank details whose trust was revoked, or that were edited after trusting, still go to your approvers. Without the option, an approver marks the beneficiary's bank account as trusted from the Beneficiaries list, or you ask them to:

   ```php
   $client->payeeTrustRequests->create([$externalAccountId]);
   ```

   `trusted` on `$client->beneficiaries->getExternalAccount()` says which it is. The API does not show whether the option is on.
7. **Run the preflight, then the example** (from `examples/transfer_authorizer/`, after `composer install` in the repository root and filling in `.env`): `php preflight.php` checks what the API shows of steps 1–6 and prints a fix for each that fails. What it cannot see, check by hand: that key A has `transfers:write`, that key B has `transfers:authorize` and is bound to an IP allowlist, and, for a payee not trusted yet, that *Approve transfers to new payees* is on and key A is bound to an IP allowlist too (it warns, it does not fail). Then `PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:4599 authorizer.php` in one terminal, `php create_transfer.php` in another.
8. **Troubleshooting**: a draft that goes to your approvers instead has an `authorization` with status `refused` and a `refusal_reason` (`create_transfer.php` prints it in words); `$client->transferAuthorizer->get()` shows a suspension and its `suspension_reason`. An authorize answered 409 `authorization_busy` (a bank-detail edit, trust or trust revocation on the same payee was in flight) is safe to retry. The reasons, and an API error's `type`, are in the [transfer authorization docs](https://ma.manza.finance/docs/api/transfer-authorization).

### Verifying the webhook

Pass the **raw request body**, never re-encoded JSON. `Webhook::constructEvent()` reads `X-Manza-Signature` and `X-Manza-Timestamp` (any case, the `$_SERVER` `HTTP_X_MANZA_*` form, list values as PSR-7 and Symfony give them, or the legacy `X-Zazu-*` names), checks the signature in constant time and the timestamp within 5 minutes, and returns the decoded event (an associative array). It throws `\InvalidArgumentException` otherwise:

```php
use Manza\Webhook;

try {
    $event = Webhook::constructEvent(file_get_contents('php://input'), $_SERVER, $signingSecret);
} catch (\InvalidArgumentException $e) {
    http_response_code(401);
    exit($e->getMessage());
}
// Laravel: Webhook::constructEvent($request->getContent(), $request->headers->all(), $signingSecret)
```

`Webhook::verify($payload, $signature, $timestamp, $signingSecret)` returns a boolean instead. Both accept `tolerance: null` to skip the timestamp check. Use it only to replay a captured delivery in a local tool or test, never on a live endpoint: without the check, anyone holding a captured delivery can replay it. `Webhook::sign()` produces a signature, for tests.

### Signing the challenge

Sign the draft from **your own record** of it with the endpoint's signing secret:

```php
use Manza\TransferAuthorization;

// $draft: your own record of the draft, e.g. the ->body of $client->payments->create() you stored

$input = TransferAuthorization::signatureInput(
    paymentId: $draft['id'],
    nonce: $event['data']['authorization']['nonce'],
    amount: $draft['amount'],                // the API's decimal string, e.g. "2500.0"
    currencyCode: $draft['currency_code'],
    accountId: $draft['account_id'],
    payee: TransferAuthorization::payeeFor(externalAccountId: $draft['external_account_id']),
    clientReference: $draft['client_reference'],
);
$signature = TransferAuthorization::sign($signingSecret, $input);

$authorizer = new Client(apiKey: getenv('MANZA_AUTHORIZER_API_KEY'));
$authorizer->payments->authorize($draft['id'], $event['data']['authorization']['id'], $signature);
$authorizer->payments->decline($draft['id'], $authorizationId, 'Not ours'); // reason is optional
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
| CLI | [getmanza/cli](https://github.com/getmanza/cli) | `npm install -g @getzazu/cli` or `brew install getmanza/tap/zazu` |

## Releasing

Maintainers: run `bin/release` from a clean, up-to-date `main` (`bin/release --help` for the options).
