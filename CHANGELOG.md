# Changelog

All notable changes to `zazu-php` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
