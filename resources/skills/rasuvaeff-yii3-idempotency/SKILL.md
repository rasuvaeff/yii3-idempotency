---
name: rasuvaeff-yii3-idempotency
description: >-
  Prevent duplicate processing of POST/PUT/PATCH requests in Yii3 APIs with
  rasuvaeff/yii3-idempotency — IdempotencyMiddleware, IdempotencyKey,
  IdempotencyFingerprint, IdempotencyStorage, IdempotencyPolicy,
  IdempotencyScope, RequestAttributeScopeResolver, FailureClassifier,
  DomainFailureRenderer. Use when writing, reviewing or debugging
  idempotency-key handling, duplicate-request replay, caller/key scoping,
  domain-failure caching, or 409/422 conflict responses in a project that has
  this package installed.
---

# rasuvaeff/yii3-idempotency

PSR-15 idempotency-key middleware: stores the response of the first request
under its `Idempotency-Key` header and replays it on retries. Namespace
`Rasuvaeff\Yii3Idempotency\`.

## Safety rules — verify these on every change

1. **Method-scoped.** The middleware applies only to its configured `methods`
   (default POST/PUT/PATCH, case-insensitive). GET/DELETE and anything else
   pass through before any key or claim work — do not expect them to dedupe.

2. **Only 2xx responses are cached.** Any non-2xx handler response (3xx/4xx —
   including retryable 409/423/429 — and any 5xx) releases the claim so the
   client can retry under the same key. Never "fix" a failing retry by caching
   an error response.

   The one exception is opt-in and applies to *thrown* failures only: with a
   `DomainFailureRenderer` configured, a throwable the `FailureClassifier` calls
   `FailureKind::Domain` is rendered, stored and replayed for the whole TTL —
   but only if the renderer actually returns a `ResponseInterface`. A renderer
   returning `null` declines the failure, and a renderer that throws is treated
   the same way: the claim is released and the original throwable is rethrown.
   `\Error`, anything implementing `RetryableFailure`, and anything overridden
   as `Bug` also release the claim. Classify conservatively — a transient
   failure mislabelled `Domain` is pinned until the record expires.

3. **Conflict semantics are fixed contract.** Same key + different payload
   fingerprint → 422; same key while the first request is still in flight
   → 409; replay (same key + same fingerprint) → cached response, handler NOT
   called. Key format: 1-255 chars, `[A-Za-z0-9._-]+`.

   A malformed key (too long, illegal characters) coming off the request is a
   400, not an unhandled exception. `MissingKeyException` still propagates.

   Every key is namespaced by the middleware's required `scopeResolver` before
   it reaches storage: `sha256(scope . "\0" . key)` — a fixed 64 chars, so
   stored records are opaque. Records are separated exactly as far as scope
   names differ.

4. **The keyspace must be partitioned per caller.** `scopeResolver` has no
   default: a keyspace shared across clients lets one client replay another
   client's cached response, and the replay path returns the stored response
   without entering the handler — so it never reaches the handler's
   authorization checks either. Use `RequestAttributeScopeResolver` for a
   multi-client API, `CompositeScopeResolver` to stack the endpoint dimension
   (`RequestTargetScopeResolver`) on top. `SharedKeyspaceScopeResolver` is the
   documented opt-out and is safe only when a single principal can reach the
   middleware. Never make a shared keyspace the default again.

   `Set-Cookie`, `Date` and hop-by-hop response headers are never captured nor
   replayed — a session identifier must not sit in a storage row for the whole
   TTL, and a stale cookie must not be handed back.

5. **Storage claim must be atomic.** `IdempotencyStorage::claim()` is a
   compare-and-set; the `-db` backend implements it atomically.
   `InMemoryIdempotencyStorage` is a testing double — never wire it in
   production. `IdempotencyRecord::restore()` is the rehydration path storage
   adapters depend on — do not remove or bypass it.

6. **DI pair: core + backend.** The core package does NOT bind
   `IdempotencyStorage` — exactly one source binds it: the backend package
   (e.g. `rasuvaeff/yii3-idempotency-db`) or the application. Binding it in
   core config causes a `yiisoft/config` `Duplicate key` runtime error.

## Canonical usage

```php
$middleware = new IdempotencyMiddleware(
    keyExtractor: new HeaderIdempotencyKeyExtractor('Idempotency-Key'),
    storage: $storage,                 // IdempotencyStorage implementation
    responseFactory: $responseFactory, // PSR-17
    clock: $clock,                     // PSR-20
    scopeResolver: new RequestAttributeScopeResolver(attribute: 'user'), // REQUIRED
    policy: IdempotencyPolicy::PassThrough, // or Reject => 400 without key
    ttlSeconds: 3600,
);
```

With the Yii3 config plugin, tune via `params.php` under
`'rasuvaeff/yii3-idempotency'` (`headerName`, `policy`, `ttlSeconds`,
`methods`, `callerAttribute`, `anonymousCaller`, `scope`). `callerAttribute` is
required — a request-attribute name, or `false` for the shared-keyspace
opt-out. `scope` accepts `'auto'` (`RequestTargetScopeResolver`), `null` (no
endpoint dimension) or an explicit scope name.

Keys can also come from the payload instead of a header:
`new PayloadIdempotencyKeyExtractor('command.orderId')` — unresolvable paths
throw `MissingKeyException` unless constructed with `required: false`.

## Full API

The complete reference — every class, the fingerprint formula, storage
interface contract and config keys — ships with the package: read
`vendor/rasuvaeff/yii3-idempotency/llms.txt` before guessing a method name.
