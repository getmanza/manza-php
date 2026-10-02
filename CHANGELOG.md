# Changelog

All notable changes to `manza-php` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Renamed (1.0.0)

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

### Changed (1.0.0)

- Tests replay the `getmanza/manza-ruby` cassettes (pinned to `v1.0.0`); fixture env vars are now `MANZA_FIXTURE_*` (no fallback, dev-only)

### Added

- `ApiException` kind `conflict` (409) with `paymentId`, read from `error.payment_id` on a duplicate transfer `client_reference`
- `transferDrafts->authorize()` (blank signature refused locally with `\InvalidArgumentException`) and `transferDrafts->decline()` (`reason` optional); `client_reference` documented on `create`
- `Manza\TransferAuthorization` signer: `signatureInput()`, `sign()` (lowercase hex HMAC-SHA256), `payeeFor()`
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

- `Manza\Client` built on Guzzle (named-argument construction, env-var fallbacks)
- Resources: `accounts`, `beneficiaries`, `checkoutSessions`, `customers`, `entity`, `invoices`, `paymentLinks`, `transferDrafts`, `webhookEndpoints`
- Cursor-based `Manza\Page` with `next()` (max 100 records per page)
- `Manza\Exception\ApiException` mirroring the shared SDK error taxonomy
- Cassette-replay test harness driven by the Ruby SDK's release tarball
