# Changelog

All notable changes to `manza-php` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `$client->payments` (`Manza\Resources\Payments`): `create()`, `get()`, `authorize()` and `decline()` on the API's canonical paths, `POST /api/payments`, `GET /api/payments/:id`, `POST /api/payments/:id/authorize` and `POST /api/payments/:id/decline`. The API renamed transfer drafts to payments; request and response bodies are unchanged, and so is the `Manza\TransferAuthorization` signature input
- `$client->transferAuthorizer->get()` (`GET /api/transfer_authorizer`): the enrolled authorizer's `status`, `suspension_reason`, limits, `daily_authorized_total`, `secret_expires_at` and `webhook_endpoint_id`. An `ApiException` of kind `not_found` when none is enrolled
- `Manza\Webhook` verifies incoming webhook deliveries: `verify()` (boolean), `constructEvent()` (returns the decoded event, throws `\InvalidArgumentException`) and `sign()`. Reads `X-Manza-*` headers in any case, the `$_SERVER` `HTTP_X_MANZA_*` form, list values and the legacy `X-Zazu-*` names; checks the timestamp within 5 minutes (`tolerance: null` skips it, for local replay only). Passes the fixed test vector every SDK in the family shares
- Documented the new pass-through fields: a draft's `authorization` carries `refusal_reason` (status `refused` when it went to the approvers at create time), bank accounts carry `trusted`, and the challenge webhook's `payment.payee_trusted` says whether the payee was trusted already
- `examples/transfer_authorizer/`: a `php -S` front controller that verifies the challenge webhook, checks it against a ledger, signs and authorizes (or declines; `ALLOW_NEW_PAYEES=false` declines new payees; retries 5xx, 429, connection failures and 409 `authorization_busy`), a creator script that prints where the API routed the draft, and a preflight that checks every setup step and prints the fix. Not shipped in the package (`.gitattributes` `export-ignore`)
- README: "Setup, step by step", verifying the webhook, and the example

### Deprecated

- `$client->transferDrafts` and `Manza\Resources\TransferDrafts`. Both keep working for all of 1.x: `TransferDrafts` is now a subclass of `Payments`, so the old name calls `/api/payments` too. The server keeps `/api/transfer_drafts` as a permanent alias

### Changed

- Tests replay `getmanza/manza-ruby`'s `cassettes-v1.3.0` (was `v1.0.0`): the payment cassettes moved from `transfer_drafts/` to `payments/`

## [1.0.0]

### Changed (breaking)

The SDK is now Manza's. Behaviour is unchanged apart from the names below.

| | Before | After |
|---|---|---|
| Package | `getzazu/zazu-php` | `manza/manza-php` |
| Repository | `getzazu/zazu-php` | `getmanza/manza-php` |
| Namespace | `Zazu\` (`Zazu\Tests\`) | `Manza\` (`Manza\Tests\`) |
| Classes | `Zazu\Client`, `Zazu\Page`, `Zazu\Exception\ApiException`, ... | `Manza\Client`, `Manza\Page`, `Manza\Exception\ApiException`, ... |
| Env vars | `ZAZU_API_KEY`, `ZAZU_BASE_URL`, `ZAZU_API_VERSION` | `MANZA_API_KEY`, `MANZA_BASE_URL`, `MANZA_API_VERSION` |
| Version header | `Zazu-Version` | `Manza-Version` |
| User-Agent | `zazu-php/<version>` | `manza-php/<version>` |

Migrating: `composer remove getzazu/zazu-php && composer require manza/manza-php`, then replace `Zazu\` with `Manza\` in your `use` statements and fully qualified names.

The `ZAZU_*` env vars keep working for all of 1.x: each is read only when its `MANZA_*` counterpart is unset, and triggers a one-time `E_USER_DEPRECATED` warning per variable. Move to `MANZA_*` before 2.0.

- Tests replay the `getmanza/manza-ruby` cassettes (pinned to `v1.0.0`); fixture env vars are now `MANZA_FIXTURE_*` (no fallback, dev-only)

## [0.3.0]

### Added

- `ApiException` kind `conflict` (409) with `paymentId`, read from `error.payment_id` on a duplicate transfer `client_reference`
- `transferDrafts->authorize()` (blank signature refused locally with `\InvalidArgumentException`) and `transferDrafts->decline()` (`reason` optional); `client_reference` documented on `create`
- `Zazu\TransferAuthorization` signer: `signatureInput()`, `sign()` (lowercase hex HMAC-SHA256), `payeeFor()`
- `beneficiaries->create()`, `listExternalAccounts()`, `getExternalAccount()`, `createExternalAccount()`
- `payeeTrustRequests` resource: `create()` and `get()`
- Docs for the new request and response fields: `client_reference`, `authorization`, `settled_at`, `transaction`, `billing_address`, `collect_billing_address`, `customer_name`, `registration_number`, `vat_number`, the `clearing` status; `tax_id`, `ice_number` and `delivery_date` are Morocco only

### Changed

- 400 now maps to kind `validation` (previously `api`)
- Default base URL is `https://ma.manza.finance` (use `https://za.manza.finance` for South Africa); replay tests run against `https://ma.manza.dev`

## [0.2.1]

Version alignment: the whole SDK family now releases in lockstep with zazu-ruby. No functional changes since [0.1.0].

## [0.1.0]

Initial release.

### Added

- `Zazu\Client` built on Guzzle (named-argument construction, env-var fallbacks)
- Resources: `accounts`, `beneficiaries`, `checkoutSessions`, `customers`, `entity`, `invoices`, `paymentLinks`, `transferDrafts`, `webhookEndpoints`
- Cursor-based `Zazu\Page` with `next()` (max 100 records per page)
- `Zazu\Exception\ApiException` mirroring the shared SDK error taxonomy
- Cassette-replay test harness driven by the Ruby SDK's release tarball
