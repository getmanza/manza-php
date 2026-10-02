# zazu-php

PHP SDK for the Zazu API (the Manza API). It replays the cassettes that **zazu-ruby**, the reference implementation, records and ships on each release; it never talks to a live API in tests. Same interactions, same assertions as every other SDK in the family.

## Stack

| Concern | Tool | Notes |
|---|---|---|
| Language | PHP ≥ 8.2 (`composer.json`) | CI runs a single version, 8.3 (`ci.yml`); there is no version matrix |
| HTTP | Guzzle ^7.8 | `Zazu\Client::request()`; swap via the `httpClient` constructor argument |
| Test runner | PHPUnit ^11 | `phpunit.xml.dist`, `failOnRisky` + `failOnWarning` |
| Cassette replay (tests) | `tests/CassetteReplayHandler.php` + `symfony/yaml` | Guzzle handler reading zazu-ruby's VCR YAML |
| Lint / format / typecheck | none configured | No php-cs-fixer, phpstan or psalm; `composer validate --strict` is the only static gate |
| Package registry | Packagist (`getzazu/zazu-php`) | Reads git tags via the GitHub hook; no OIDC, no publish token |
| Release | `bin/release` | zazu SDK release kit; repo-specific bits in `scripts/version` + `scripts/release-check` |

## Public API surface

```php
use Zazu\Client;
use Zazu\TransferAuthorization;
use Zazu\Exception\ApiException;

$zazu = new Client(apiKey: 'sk_live_...');   // or ZAZU_API_KEY

$zazu->entity->get();
$zazu->accounts->list();                     // returns Zazu\Page; ->next() for more
$zazu->beneficiaries->create([...]);
$zazu->beneficiaries->listExternalAccounts($beneficiaryId);
$zazu->beneficiaries->getExternalAccount($beneficiaryId, $externalAccountId);
$zazu->beneficiaries->createExternalAccount($beneficiaryId, [...]);
$zazu->payeeTrustRequests->create([$externalAccountId]);
$zazu->payeeTrustRequests->get($id);
$zazu->transferDrafts->create([... 'client_reference' => 'po_1']);

// Machine authorization: sign from your own record, never from the server's signature_input
$input = TransferAuthorization::signatureInput(paymentId: ..., nonce: ..., amount: ..., currencyCode: ..., accountId: ...,
    payee: TransferAuthorization::payeeFor(externalAccountId: ...), clientReference: ...);
$signature = TransferAuthorization::sign($secret, $input);   // lowercase hex HMAC-SHA256
$zazu->transferDrafts->authorize($draftId, $authorizationId, $signature);  // blank signature -> \InvalidArgumentException
$zazu->transferDrafts->decline($draftId, $authorizationId, 'reason');      // reason optional

try { $zazu->transferDrafts->create([...]); }
catch (ApiException $e) { if ($e->kind === 'conflict') { $e->paymentId; } }
```

Resources: `accounts`, `beneficiaries`, `checkoutSessions`, `customers`, `entity`, `invoices`, `payeeTrustRequests`, `paymentLinks`, `transferDrafts`, `webhookEndpoints`.

- `Zazu\Page`: cursor-based pagination (`data`, `hasMore`, `nextCursor`, `next()`), hard cap of 100 per page (`Page::MAX_PER_PAGE`)
- Errors: **one** class, `Zazu\Exception\ApiException`, discriminated by `$e->kind`, never by status code. Eight kinds (with `ConnectionException` and `ConfigurationException` that makes the family's ten): `authentication` (401), `forbidden` (403), `not_found` (404), `validation` (400, 422), `conflict` (409, carries `paymentId`), `rate_limit` (429, carries `retryAfter`), `server` (5xx), `api` (anything else). Transport failures throw `ConnectionException`, bad configuration throws `ConfigurationException`.
- Snake-case wire format: responses are associative arrays with the API's keys, no typed models. **No auto-camelCasing.**

## How to work in this codebase

1. **Tests come first.** Every change to `src/` ships with a test. Cassette-replay tests are the contract; they enforce the same wire format across Ruby, TS, PHP and the rest.
2. **Use the SDK's primitives.** `Zazu\Page`, `ApiException::$kind`, `Client::encodePath()` for URL construction, `Client::request()`/`listPage()` for HTTP, `FixtureIds::id()` in tests. Don't hand-roll Guzzle calls or string-interpolate paths.
3. **Snake-case stays.** Response keys are wire format. We don't camelCase them.
4. **Keep `composer validate --strict` and PHPUnit green.** There is no linter; match the surrounding style (`declare(strict_types=1)`, readonly properties, named arguments) and don't add suppressions.

## Critical rules

- **Never call a live Zazu/Manza API** from tests, scripts or Claude sessions. Tests replay zazu-ruby's cassettes only. Live staging calls create real transfers and approval requests for the team. Only zazu-ruby records cassettes.
- **Cassette contract.**
  - Cassettes come from the newest zazu-ruby `v*` release (`cassettes-vX.Y.Z.tar.gz`) via `scripts/fetch-cassettes.sh`; they land in `tests/fixtures/cassettes/` (git-ignored).
  - They are recorded against `https://ma.manza.dev`; the replay handler ignores the host.
  - Load one cassette per test: `transfer_drafts/authorize` vs `authorize_same_key` (and `authorize_bad_signature`), and `create` vs `create_duplicate`, share method + URI, so loading both makes the first one win.
  - The three authorize cassettes match the body minus `signature` (`CassetteReplayHandler::clientIgnoringSignature()`).
  - Every other cassette matches method, path, query (key order ignored) and **semantic JSON body** (decoded, string keys sorted, list order kept; non-JSON bodies compare byte for byte).
  - Cassette responses carry no `Content-Length`; the handler synthesises a response with only `Content-Type`.
  - `tests/FixtureIds.php` must stay identical to zazu-ruby's `spec/support/fixture_ids.rb` (same env var names, same placeholders).
- **Hosts.** Default `https://ma.manza.finance` (Morocco), South Africa `https://za.manza.finance`, staging and cassettes `https://ma.manza.dev`. Env var names stay `ZAZU_*` and the namespace stays `Zazu` until the rename plan (zazu-ruby `docs/plans/2026-10-manza-rename.md`).
- **The error model is shared across the SDK family.** Adding an error kind means coordinating zazu-ruby and zazu-ts at minimum. The tenth is the conflict (409), kind `conflict`.
- **Signer.** `TransferAuthorization` must keep reproducing the two fixed vectors in `tests/TransferAuthorizationTest.php` (mirrors zazu-ruby's `spec/zazu/transfer_authorization_spec.rb`). Never sign the server's `signature_input` blindly: build it from your own record of the draft.
- **Release.** `bin/release` is byte-identical across the SDK repos and never edited in place. Repo-specific logic lives in `scripts/version` (reads/writes `Client::VERSION`) and `scripts/release-check` (fetch cassettes, composer validate, install, phpunit). `release.yml` fails the release unless the tag equals `Client::VERSION`. Packagist has no trusted publishing and this repo holds no secret: the package syncs from GitHub tags, so the Packagist repository URL must point at `getmanza/zazu-php`. Verify it at https://packagist.org/packages/getzazu/zazu-php if versions stop appearing.
- **The repo moved from `getzazu` to `getmanza`.** Remotes and URLs must say `getmanza`. (Known leftovers: `composer.json` still names `getzazu/zazu-php` and its homepage, and `scripts/fetch-cassettes.sh` points at `getzazu/zazu-ruby`; GitHub redirects work, but don't spread the old org further.)
- **Snake-case wire format.** API request/response bodies use snake_case. Don't transform them.
- **Never escape backticks in PR bodies.** With `<<'EOF'` (single-quoted heredoc) the shell passes everything through verbatim. See "PR descriptions" below.

## PR descriptions

Write PR description bodies in plain Markdown. **Do not escape backticks** with `` \` `` — GitHub renders `` \` `` literally as a backslash followed by a backtick, producing output like `` \`Zazu\Page\` `` instead of the monospace `Zazu\Page` the reader expects.

The usual cause is writing the description inside a bash heredoc (`gh pr create --body "$(cat <<'EOF' ... EOF)"`) and then reflexively escaping every backtick because of shell-quoting muscle memory. With `<<'EOF'` (single-quoted delimiter) the shell does NOT interpret anything inside the heredoc — backticks, dollars, and backslashes all pass through verbatim. So write them exactly as you want them rendered:

```bash
# Good — renders as `Zazu\Page` in monospace
gh pr create --body "$(cat <<'EOF'
Uses the `Zazu\Page` helper.
EOF
)"

# Bad — renders as \`Zazu\Page\` literally in the PR body
gh pr create --body "$(cat <<'EOF'
Uses the \`Zazu\Page\` helper.
EOF
)"
```

Same rule for code blocks — write triple-backticks unescaped. The single-quoted heredoc delimiter is doing all the shell-escaping work. If you find yourself typing `` \` `` inside a PR body, stop and remove the backslash.

## Striving for excellence

These are the Karpathy guidelines we apply on every change. They reduce common LLM coding mistakes.

### 1. Think before coding

Don't assume. Don't hide confusion. Surface tradeoffs.

- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them — don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

### 2. Simplicity first

Minimum code that solves the problem. Nothing speculative.

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

Senior engineer test: would they call this overcomplicated?

### 3. Surgical changes

Touch only what you must. Clean up only your own mess.

- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it — don't delete it.
- Remove imports/variables/methods that *your* changes orphaned. Don't remove pre-existing dead code unless asked.

### 4. Goal-driven execution

Define success criteria. Loop until verified.

- "Add validation" → "Write tests for invalid inputs, then make them pass"
- "Fix the bug" → "Write a test that reproduces it, then make it pass"
- "Refactor X" → "Ensure tests pass before and after"

For multi-step tasks, state a brief plan with verification at each step.

## Development workflow

Commands are the ones in `.github/workflows/ci.yml`. CI uses PHP 8.3; local PHP may be newer (the last full run here was on 8.5.11, green).

```bash
# One-time setup
scripts/fetch-cassettes.sh        # latest zazu-ruby release; or scripts/fetch-cassettes.sh v0.3.0
composer install --no-interaction --prefer-dist

# Daily loop
vendor/bin/phpunit tests/ResourcesTest.php   # while iterating
vendor/bin/phpunit                           # full suite
composer validate --strict                   # the only static check CI runs

# Release (after PR merge, from a clean, up-to-date main)
bin/release list        # last releases + what patch/minor/major would give
bin/release --dry-run   # version + changes since the last tag, publishes nothing
bin/release minor       # or patch (default), major, an explicit 0.4.0; --force re-creates
# → bumps Client::VERSION, runs scripts/release-check, pushes main, publishes the GH release
# → release.yml fails the release when the tag does not match Client::VERSION
# → Packagist picks the tag up via its GitHub hook
```

## Models

Sessions run on `opus` (Opus 5.5) with `fable` (Fable 5.1) as the advisor (`.claude/settings.json`). Fable is spent where judgment matters most: plans are written on Fable (a `fable` subagent or `/model fable`; plan mode itself runs on Opus and asks the advisor), the advisor is consulted at decision points (before choosing an approach, a schema or public API, a migration, a dependency, anything irreversible, and when a failure repeats), and the `fable-validator` agent checks every finished implementation before its pull request opens (`/lfg`, Phase 6.5). Agents and commands pin their tier by alias, never by full model ID: `fable` for plans and validation; `opus` for orchestration, security, full PR review, payments and production debugging; `sonnet` for the implementation specialists and TDD; `haiku` for mechanical scans. Every spawned agent names its `model:`; one that does not runs on `sonnet` (`CLAUDE_CODE_SUBAGENT_MODEL`), never on the session's model.

## Slash commands

These live in `.claude/commands/` and are available in any Claude Code session:

| Command | When |
|---|---|
| `/lfg <issue or feature>` | Full autonomous workflow with TDD + verification |
| `/github-review-pr <PR#>` | Full PR review pass: failures first, then comments |
| `/github-review-failures <PR#>` | Just fix CI failures on a PR |
| `/github-review-comments <PR#>` | Just respond to reviewer comments on a PR |
| `/coderabbit-review <PR#>` | Specifically address CodeRabbit findings (verify, fix valid, push back on stale/wrong) |

## Cross-SDK contract

`zazu-ruby` is the reference implementation:

- Records cassettes against `https://ma.manza.dev`
- Ships them as a release tarball (`cassettes-vX.Y.Z.tar.gz`) on each version
- All other SDKs (`zazu-ts`, `zazu-python`, `zazu-go`, this one, `zazu-crystal`, `zazu-elixir`, `zazu-rust`) replay these cassettes in their own test harness

If the contract breaks (e.g., a new request shape), it's a coordinated change across at least two repos: zazu-ruby and zazu-php (and zazu-ts).

## Repository links

- Ruby SDK (reference): https://github.com/getmanza/zazu-ruby
- TypeScript SDK: https://github.com/getmanza/zazu-ts
- This repo: https://github.com/getmanza/zazu-php
- Packagist: https://packagist.org/packages/getzazu/zazu-php
