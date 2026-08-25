# Changelog

## Unreleased

- Non-seekable request bodies are no longer drained for the handler: the
  middleware restores the content with a fresh seekable stream before taking
  the fingerprint, and a drained non-seekable response body is restored before
  the response is returned — previously the first client received an empty
  body while every replay got the full one (#21).
- `IdempotencyFingerprint::equals()` compares hashes with `hash_equals()`
  instead of `===`, closing the theoretical timing channel on fingerprint
  comparison (#21).

## 2.0.0 — 2026-08-22

- **BREAKING (security).** `IdempotencyMiddleware::__construct()` takes a new
  required `IdempotencyScopeResolver $scopeResolver` argument, and the bundled
  `config/params.php` key `callerAttribute` is required — the container refuses
  to build the middleware while it is unset. Idempotency keys were global: a
  client that knew (or guessed) another client's key and reproduced its payload
  got that client's cached response replayed, and the replay path returns the
  stored response without entering the handler, so it never reached the
  handler's authorization checks. The same gap let a client occupy someone
  else's key for the whole TTL. New `RequestAttributeScopeResolver` namespaces
  the key by the authenticated principal in a request attribute;
  `SharedKeyspaceScopeResolver` is the documented opt-out that restores the old
  one-keyspace-for-everyone behaviour; `CompositeScopeResolver` stacks the
  endpoint dimension on top. The `scope` param now defaults to `'auto'`, and
  `IdempotencyKeyExtractor` is bound to the bare header extractor — scoping
  moved from the extractor to the middleware. Existing stored records become
  unreachable under the new keys; the table is a TTL cache, so they simply
  expire.
- `Set-Cookie`, `Date` and hop-by-hop response headers are no longer captured
  nor replayed, so an identifier carried by one of them — a session cookie above
  all — no longer sits in a storage row in plaintext for the whole TTL, and a
  stale cookie is no longer handed back on replay. Identifiers in the response
  body or in other custom headers are untouched: exclude those explicitly. The
  deny-list can be extended via the new `additionalExcludedResponseHeaders`
  constructor argument; it is added to the built-in list, which cannot be
  switched off.
- A malformed idempotency key from an untrusted request now answers 400 instead
  of raising an unhandled `InvalidArgumentException` (a 500). `MissingKeyException`
  keeps propagating — a `required: true` extractor raising it is a deliberate
  contract, and a scope resolver failing is a deployment error, so neither is
  turned into a 400.
- `IdempotencyScope::of()` builds a scope from a name of any length, collapsing
  one longer than 1024 characters to its hash. `RequestTargetScopeResolver` uses
  it, so a very long request path no longer turns into a 500 for its length.
  Length is the only relaxation: a control character is still rejected at any
  length, so hashing cannot launder a name the constructor refuses.
- `CompositeScopeResolver` length-prefixes every component before joining them.
  The separator is legal inside a scope name, so a plain join was not injective:
  `['a | b', 'c']` and `['a', 'b | c']` produced the same scope, and therefore
  the same storage key.
- `RequestAttributeScopeResolver` encodes the caller *state* as well as the
  identity (`caller:identity:<id>` against `caller:anonymous:<name>`). An
  authenticated caller whose identity equalled the anonymous name used to land
  in the namespace every unauthenticated request shares.
- Raised `rasuvaeff/property-testing-testo` to `^0.6`.

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

