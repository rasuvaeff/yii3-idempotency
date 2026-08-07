# Changelog

## Unreleased

- Failure classification and domain-failure caching: `FailureKind`,
  `FailureClassifier`, `DefaultFailureClassifier`, the `RetryableFailure` marker
  and `DomainFailureRenderer`. With a renderer configured, a thrown domain
  failure is rendered, stored as an ordinary response snapshot and replayed on
  retry instead of re-running the handler. Without one, nothing changes.
- Key scoping: `IdempotencyScope`, `IdempotencyScopeResolver`,
  `RequestTargetScopeResolver` and the `ScopedIdempotencyKeyExtractor`
  decorator. The storage key becomes `sha256(scope . "\0" . key)`, so the same
  key reused across endpoints no longer collides. Configurable via the new
  `scope` param (`null` — global, `'auto'`, or an explicit name).
- `PayloadIdempotencyKeyExtractor`: reads the key out of the parsed body by dot
  path (`command.orderId`) for queue and command-bus consumers. An unresolvable
  path throws the new `MissingKeyException` unless `required: false`.

## 1.1.1 — 2026-07-25

- Reject trailing newlines in `IdempotencyKey`: anchor the validation pattern
  with `\z` instead of `$` (PCRE `$` matches before a trailing `\n`, which let
  `"<key>\n"` slip through and become the storage key).

## 1.1.0 — 2026-07-25

- Ship an AI agent skill (`resources/skills/rasuvaeff-yii3-idempotency/SKILL.md` +
  `extra.skills` in composer.json): projects using the `llm/skills` Composer
  plugin get the skill synced into `.agents/skills/` automatically on install.
- Bump `rasuvaeff/property-testing` dev dependency to `^2.6`.
- Make property-test generator methods `public static` (guards against rector
  `RemoveUnusedPrivateMethodRector` removing reflection-only calls).

## 1.0.1 — 2026-06-30

- Add `/benchmarks` and `/Makefile` to `.gitattributes` export-ignore.

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.1 — 2026-06-27

- Migrate test suite from PHPUnit to Testo. Internal change, no public API impact.

## 1.0.0 — 2026-06-12

- `IdempotencyMiddleware` — PSR-15 middleware; replays stored responses on duplicate requests. Applies only to the configured `methods` (default POST/PUT/PATCH, case-insensitive); other methods pass through untouched.
- `HeaderIdempotencyKeyExtractor` — extracts idempotency key from configurable request header.
- `IdempotencyKey` — validated value object: 1–255 chars, `[A-Za-z0-9._-]+`.
- `IdempotencyFingerprint` — SHA-256 of method + path + query + body; detects payload
  substitution. Rewinds the request body stream after reading.
- `IdempotencyRecord` and `IdempotencyResponse` — stored state with TTL;
  `IdempotencyRecord::restore()` rehydrates records in storage adapters.
- `IdempotencyStorage` interface for persistent adapters; `InMemoryIdempotencyStorage` for tests.
- `IdempotencyPolicy` enum: `PassThrough` (skip without key) or `Reject` (400 without key).
- 422 Unprocessable Content on same key + different payload; 409 Conflict while the
  first request is still in flight.
- Only 2xx handler responses are cached; any non-2xx (3xx/4xx — incl. retryable 409/423/429 — and 5xx) releases the claim so the client can retry under the same key.

