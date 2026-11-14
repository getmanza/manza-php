# Changelog

All notable changes to `manza-php` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.0.0] - 2026-11-14

Synced to the Manza API at getmanza/app `906ec486e5` (API version `2026-11-13`). A major release: see Migrating from 1.x below.

### Removed

- **Breaking:** `$client->invoices->createPaymentLink()` (`POST /api/invoices/:id/payment_link`). The API retired the endpoint: ask for the link with `generate_payment_link: true` on `invoices->create()` or `invoices->update()` instead
- **Breaking:** the v1 transfer-authorization signer. `Manza\TransferAuthorization` builds only the v2 input (below)

### Changed

- **Breaking:** `TransferAuthorization::SIGNATURE_VERSION` is `manza.transfer-authorization.v2`, and `signatureInput()` takes a required `?string $transferMethod`, appended after `client_reference` (`""` when null). `$clientReference` is now required too (null when the transfer has none), so the two trailing parameters follow the wire order and a 1.x positional call that passed the client reference seventh throws an `\ArgumentCountError` instead of signing a wrong input: `manza.transfer-authorization.v2|payment_id|nonce|amount|currency_code|account_id|payee|client_reference|transfer_method`. The API verifies v2 for every request that is unpinned or sends `Manza-Version: 2026-11-13` or later; only `2026-03-27` still verifies v1. Pass the payment's `transfer_method` as the API returned it
- `Manza\Exception\ApiException` is no longer `final`, so `PlanLimitReachedException` can extend it. Don't subclass it yourself
- The example authorizer (`examples/transfer_authorizer`) records the create response's `transfer_method` in its ledger, checks it against the webhook's `payment.transfer_method`, signs v2 and compares its input with the webhook's `signature_input_v2`. `Ledger::record()` takes `transferMethod`
- Invoice docs: `account_id` is required on `invoices->create()` (without one the API answers 422 `state_error`; an account that is not the entity's, 422 with `param` `account_id`), and `invoices->update()` takes it too. An invoice's `status` may be `declined`, and `invoices->delete()` also takes a `declined` invoice that has no number
- Tests replay `getmanza/manza-ruby`'s `cassettes-v2.0.0` (was `v1.3.0`)

### Added

- `Manza\Exception\PlanLimitReachedException`, a subclass of `ApiException` thrown for a 422 whose `type` is `plan_limit_reached` (a plan cap, which the API used to answer with a 500). Its `kind` stays `validation`; `limitKind` (the API's `kind`, e.g. `invoices`), `current` and `max` read the cap from the error body
- `generate_payment_link` on `invoices->create()` and `invoices->update()` (needs the `payment_links:write` scope; taken only while payment links are enabled for the entity). Invoice responses carry `generate_payment_link` and the embedded `account`
- `transfer_method` on `payments->create()`: `eft` or `rtc` in South Africa, `standard` or `instant` in Morocco; one the market does not offer throws a `validation` `ApiException` with `param` `transfer_method`. Payment responses carry `transfer_method` (the rail sent, the provider's default when left out, null where the market offers no choice)
- Documented: the authorization `refusal_reason` `rail_limit_exceeded` (status `superseded`): an authorized transfer over its rail's limit at execution, sent to the approvers; the authorize call throws a 422 `validation` `ApiException` (`state_error`). A non-boolean `generate_payment_link` throws a `validation` `ApiException` (400)
- Documented: the `payment.authorization_requested` webhook's `signature_input_v2` and `payment.transfer_method`, and `transferAuthorizer->get()`'s `trust_new_payees` and `new_payee_limit`

### Fixed (API side, no wire change)

- `accounts->listTransactions()` and `accounts->getTransaction()` return posted movements only (getmanza/app 818432457a); the SDK is unchanged

### Migrating from 1.x

- **`createPaymentLink()` is gone.** Replace `$client->invoices->createPaymentLink($id, $accountId)` with `$client->invoices->update($id, ['account_id' => $accountId, 'generate_payment_link' => true])`, or pass `'generate_payment_link' => true` on create. `update()` returns the invoice, not a link: the link is made from the invoice's account when the invoice is approved, and appears in the invoice's `payment_link`. `update()` takes only an invoice that is still `draft`, `pending_approval` or `declined` (any other throws a `validation` `ApiException`, `state_error`); for an invoice already approved or sent there is no API path to attach a link to it: `$client->paymentLinks->create(['account_id' => ..., 'amount' => ...])` makes a standalone link instead
- **`account_id` is required on invoice create.** Pass the account the invoice is paid into (`$client->accounts->list()`); 1.x calls without it now throw a `validation` `ApiException` (422 `state_error`)
- **v2 signatures only.** Add `transferMethod: $payment['transfer_method']` to every `TransferAuthorization::signatureInput()` call (and `clientReference: null` where you left it out), and compare with the webhook's `signature_input_v2` instead of `signature_input`. A client pinned to `Manza-Version: 2026-03-27` (`apiVersion:` or `MANZA_API_VERSION`) is verified v1 and must stay on manza-php 1.x; unpin it, or pin `2026-11-13`, to move to 2.0
- **Plan caps.** A `catch` that tells server errors apart by `$e->kind === 'server'` around creates that may hit a plan cap no longer sees one: catch `PlanLimitReachedException` (or check `kind` `validation`), and do not retry it
- **Still working in 2.0.** The legacy `ZAZU_*` env fallback and the deprecated `$client->transferDrafts` alias are kept in 2.0 (with their deprecation warnings); move to `MANZA_*` and `$client->payments` at your own pace
- The example's `Ledger::record()` takes a required `transferMethod`; see `examples/transfer_authorizer/README.md` for ledger entries written by the 1.x example

## [1.1.0]

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
