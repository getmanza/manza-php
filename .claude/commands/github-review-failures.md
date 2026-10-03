---
description: "Use when CI checks are failing on a PR — fetches failure logs, diagnoses root causes, implements fixes, pushes until CI is green."
model: opus
argument-hint: "PR number (e.g., 1690 or #1690)"
allowed-tools: Bash(gh pr view:*), Bash(gh pr checks:*), Bash(gh pr diff:*), Bash(gh api:*), Bash(gh run view:*), Bash(git log:*), Bash(git diff:*), Bash(git push:*), Bash(git commit:*), Bash(git add:*), Bash(composer:*), Bash(vendor/bin/phpunit:*), Bash(scripts/fetch-cassettes.sh:*), Read, Write, Edit, Glob, Grep, Agent
---

# Fix GitHub CI Failures: $ARGUMENTS

Diagnose and fix CI failures. Work systematically: identify failures → read logs → diagnose root cause → fix locally → verify → push.

## Phase 0: Determine the PR

Number → PR. `#N` → strip `#`. Empty → current branch (`gh pr view --json number`).

## Phase 1: Inventory failures

```bash
gh pr checks <PR>
```

For each failing check, get the run id and load the failed logs:

```bash
gh run view <run-id> --log-failed
```

Categorize:
- **Test failures** — assertion failed, snapshot mismatch, timeout
- **Composer failures** — `composer validate --strict` (invalid `composer.json`), `composer install` resolution
- **Cassette fetch failures** — `scripts/fetch-cassettes.sh` could not download the manza-ruby tarball
- **Cassette replay failures** — `CassetteReplayHandler` threw "no cassette interaction matches"
- **Release failures** — `release.yml` tag-vs-`Client::VERSION` gate, or Packagist not picking up the tag

## Phase 2: Diagnose

Read the actual error message, not the surrounding noise. The first stacktrace line that points at our code is usually the culprit.

For each failure:

### Reproduce locally

```bash
# Cassettes
scripts/fetch-cassettes.sh

# Test
vendor/bin/phpunit tests/path/to/FileTest.php

# Full pipeline (what ci.yml runs)
composer validate --strict
composer install --no-interaction --prefer-dist
vendor/bin/phpunit
```

If you can't reproduce locally, the failure is environmental (CI-only):
- Different PHP version → CI runs 8.3 (`php-version` in `ci.yml`), your local PHP may be newer; `composer.json` allows `>=8.2`
- Missing dependency → did `composer install` run before the failing step? (`composer.lock` is git-ignored, so CI resolves fresh)
- Race condition → re-running the job fixes it
- Network → GitHub release download (cassette tarball) hiccup; the script already retries

### Find the root cause

Apply the five-whys ladder until you reach a fix point that prevents the same class of failure recurring. Don't:

- Disable the failing test
- Loosen a cassette matcher or skip the signature check to make a replay pass
- Call a live API to "confirm" a fix
- `composer require` a package instead of fixing your import

These hide the failure; the underlying bug returns elsewhere.

## Phase 3: Fix and verify

### 3.1 Implement the fix

Touch only what the failure cites, plus what the fix requires.

### 3.2 Run the equivalent local check

The CI step that failed has a local equivalent — run it, get green:

| CI step | Local equivalent |
|---|---|
| Fetch cassettes | `scripts/fetch-cassettes.sh` |
| Composer validate | `composer validate --strict` |
| Install dependencies | `composer install --no-interaction --prefer-dist` |
| PHPUnit | `vendor/bin/phpunit` |

### 3.3 Run the full pipeline

```bash
composer validate --strict && vendor/bin/phpunit
```

### 3.4 Commit + push

```bash
git add <files>
git commit -m "fix(ci): <what was failing>

<root cause and how this addresses it>"
git push origin <branch>
```

Use `fix:` for prod fixes, `chore(ci):` for workflow / config changes.

## Phase 4: Watch the next run

```bash
gh pr checks <PR> --watch
# or
gh run watch <run-id> --exit-status
```

Track until green. If the same step fails again with a different error, repeat. If it fails the same way, your fix is wrong — revert and rethink.

## Phase 5: Verify and document

```bash
gh pr checks <PR>            # all green
gh pr view <PR> --json mergeable,reviewDecision
```

If the failure was CI-config drift (workflow YAML out of sync with reality), also update relevant docs:
- `composer.json` `require.php`
- `CLAUDE.md` if a convention changed

## Common patterns and fixes

### Cassette replay says "no handler matched"

The recorded request shape drifted from what the SDK now sends, or two cassettes sharing method + URI were loaded in one test. Either:
- Fix the SDK to send what manza-ruby recorded; the cassette is the contract
- Re-record via manza-ruby (never here) and ship a new SDK version

Bodies match as semantic JSON (key order ignored); the three `transfer_drafts/authorize*` cassettes match minus `signature` and need `clientIgnoringSignature()`.

### "file not found (run scripts/fetch-cassettes.sh first)"

`tests/fixtures/cassettes/` is git-ignored. Run `scripts/fetch-cassettes.sh`. The script resolves the pinned manza-ruby tag (`v1.0.0`); a brand-new cassette needs a manza-ruby release and a deliberate bump of the pin.

### Release says `src/Client.php does not carry X`

`release.yml` requires the tag to equal `Client::VERSION`. `bin/release` writes it via `scripts/version`; never tag by hand.

### Packagist shows no new version

Packagist has no OIDC: it syncs from git tags via the GitHub hook. Check the package's repository URL points at `getmanza/manza-php`.

## Karpathy guidelines

- **Think before coding** — read the actual error, don't pattern-match on the first guess.
- **Goal-driven execution** — the green CI check is the verification.
- **Surgical changes** — fix the failing class of error, not adjacent things.
