# AGENTS.md — yii3-idempotency

Guidance for AI agents working on this package. Read before changing code.

## What this is

Idempotency key PSR-15 middleware for Yii3 APIs. Prevents duplicate processing of POST/PUT/PATCH
requests by storing and replaying responses. Supports atomic claim, conflict detection (different
payload, same key), and TTL-based expiration.

Namespace: `Rasuvaeff\Yii3Idempotency`.
Public API: `IdempotencyMiddleware`, `IdempotencyKey`, `IdempotencyFingerprint`, `IdempotencyRecord`,
`IdempotencyResponse`, `IdempotencyStorage`, `ClaimedFingerprintProvider`, `InMemoryIdempotencyStorage`,
`HeaderIdempotencyKeyExtractor`, `PayloadIdempotencyKeyExtractor`,
`ScopedIdempotencyKeyExtractor`, `IdempotencyScope`, `IdempotencyScopeResolver`,
`RequestTargetScopeResolver`, `RequestAttributeScopeResolver`,
`SharedKeyspaceScopeResolver`, `CompositeScopeResolver`, `IdempotencyPolicy`, `FailureKind`, `FailureClassifier`,
`DefaultFailureClassifier`, `RetryableFailure`, `DomainFailureRenderer`, `MissingKeyException`.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **The keyspace is partitioned per caller, and the safe default stays safe.**
   `IdempotencyMiddleware::$scopeResolver` is a required constructor argument on
   purpose: a keyspace shared across clients lets one client replay another
   client's cached response, and the replay path returns the stored response
   without entering the handler — so it never reaches the handler's
   authorization checks either. Never give `$scopeResolver` a default, never
   make `callerAttribute` optional in `config/di.php`.
   `SharedKeyspaceScopeResolver` is the documented opt-out and must stay an
   explicit, named choice.
4. **Atomic claim.** Storage MUST support atomic claim. `InMemoryIdempotencyStorage`
   is a testing double — production uses persistent adapters.
5. **Preserve the public contract.** Update README **and `README.ru.md`** +
   tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`composer.lock` is gitignored (library).
`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## Invariants & gotchas

- `IdempotencyKey` validates: 1-255 chars, pattern `/^[A-Za-z0-9._-]+\z/` (`\z`, not `$` — PCRE `$` matches before a trailing `\n`).
- Fingerprint: `sha256(method + "\n" + path + "\n" + query + "\n" + body)`; the body
  stream is rewound after reading (when seekable). A **non-seekable** request
  body is restored with a fresh seekable `BufferedStream` *before* the
  fingerprint drains it — otherwise the handler receives an already-drained
  stream; the same restoration applies to a drained non-seekable response body,
  so the first client gets the full body, not just every replay.
  Fingerprint comparison goes through `hash_equals()` — never `===`.
- Conflict semantics: 422 for payload mismatch, 409 for an in-flight duplicate.
  A failed `claim()` is a duplicate — unless the storage implements
  `ClaimedFingerprintProvider` and its in-flight fingerprint differs from the
  request's, which is a mismatch (422, not a retryable 409). The capability is
  optional; storages without it keep the plain 409. `store()` finishes the
  in-memory claim and a stored record blocks a fresh `claim()` — the same way
  the unique primary key does in a persistent adapter.
- Only 2xx handler responses are cached; any non-2xx (3xx/4xx — incl. retryable
  409/423/429 — and 5xx) releases the claim instead, so transient failures stay
  retryable under the same key.
- Thrown failures are cached only when a `DomainFailureRenderer` is configured
  **and** the `FailureClassifier` returns `FailureKind::Domain`. The cached
  outcome is an ordinary `IdempotencyResponse` snapshot — no storage schema
  change, no new `IdempotencyRecord` field, so `-db` is unaffected. Everything
  else (`\Error`, `RetryableFailure`, `Bug` overrides, a renderer returning
  `null`) releases the claim and rethrows.
- **A claim must never outlive the request that took it.** Only what the handler
  throws is classified; a storage failure is never mistaken for a domain outcome,
  and any failure inside the caching path (classifier, renderer, `store()`) is
  caught, the claim released and the *original* throwable rethrown. Otherwise a
  broken renderer answers every later request under that key with 409 until the
  claim TTL expires — and forever in a storage without one.
- Scoping lives in the middleware: `process()` applies
  `$scopeResolver->resolve($request)->apply($key)` before touching storage, so
  the storage key is `sha256(scope . "\0" . key)`. Hashing rather than prefixing
  is deliberate — a prefix would push a 250-char client key past the 255-char
  limit and reject a request that used to work. Do not "improve" it into a
  readable prefix without solving that.
  `ScopedIdempotencyKeyExtractor` still does the same at the extractor level and
  is kept for compatibility; do not route new work through it.
- `IdempotencyScope::of()` collapses a name longer than 1024 characters to its
  hash instead of throwing. Every resolver that builds a name out of request
  data must use it — the constructor throwing on *length* there would be a
  client-triggered 500. Length is the only relaxation: control characters are
  validated on the original name, before any hashing, so an over-long name can
  never launder a value the constructor refuses.
- `CompositeScopeResolver` length-prefixes each component (`<len>:<name>`) before
  joining with `' | '`. The separator is legal inside a scope name, so a plain
  join is not injective and two different compositions would share one storage
  key. Any change to the join must keep it decodable.
- `RequestAttributeScopeResolver` encodes caller *state*, not just identity:
  `caller:identity:<id>` against `caller:anonymous:<name>`. Dropping the tag puts
  an authenticated caller identified as `anonymous` into the shared anonymous
  namespace.
- The 400-on-malformed-key guard wraps ONLY `keyExtractor->extract()`. The scope
  resolver runs outside it: its failures come from what the application put in a
  request attribute, and reporting a deployment error as a bad request would
  hide it.
- `captureHeaders()` always drops `Set-Cookie`, `Date` and hop-by-hop headers;
  `additionalExcludedResponseHeaders` only extends that list and must never be
  able to replace it — hiding one custom header must not re-enable storing and
  replaying `Set-Cookie`. Anything added to the deny-list also stays out of
  `replayResponse()` by construction — the replay only knows what was captured.
- `PayloadIdempotencyKeyExtractor` throws `MissingKeyException` from `extract()`,
  which runs *before* the claim — there is nothing to release and the classifier
  never sees it. It is a `RuntimeException`, so the 400 guard (which catches only
  `InvalidArgumentException`) does not swallow it. Keep both facts that way.
- Idempotency applies only to the configured `methods` (default POST/PUT/PATCH,
  normalized to upper-case); other methods pass through before any key/claim work.
  Configurable via the `methods` constructor arg / `methods` param.
- `IdempotencyRecord::restore()` is the rehydration path for storage adapters —
  do not remove it, external backends depend on it.
- `IdempotencyRecord` uses PSR-20 `ClockInterface` for TTL.
- `captureHeaders` iterates `getHeaders()` (returns `array<string, list<string>>`).
- `replayResponse` restores headers from captured response.
- In-memory storage clears expired records on `load()`.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.

- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.

## When you finish

- Update `README.md` (and `examples/` if usage changed); update `CHANGELOG.md`
  when releasing.
- Re-run `composer build`; if the change affects the public API or release
  process, also run `make release-check`. Paste the output.
