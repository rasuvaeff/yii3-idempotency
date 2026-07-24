---
name: rasuvaeff-yii3-idempotency
description: >-
  Prevent duplicate processing of POST/PUT/PATCH requests in Yii3 APIs with
  rasuvaeff/yii3-idempotency — IdempotencyMiddleware, IdempotencyKey,
  IdempotencyFingerprint, IdempotencyStorage, IdempotencyPolicy. Use when
  writing, reviewing or debugging idempotency-key handling, duplicate-request
  replay, or 409/422 conflict responses in a project that has this package
  installed.
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

3. **Conflict semantics are fixed contract.** Same key + different payload
   fingerprint → 422; same key while the first request is still in flight
   → 409; replay (same key + same fingerprint) → cached response, handler NOT
   called. Key format: 1-255 chars, `[A-Za-z0-9._-]+`.

4. **Storage claim must be atomic.** `IdempotencyStorage::claim()` is a
   compare-and-set; the `-db` backend implements it atomically.
   `InMemoryIdempotencyStorage` is a testing double — never wire it in
   production. `IdempotencyRecord::restore()` is the rehydration path storage
   adapters depend on — do not remove or bypass it.

5. **DI pair: core + backend.** The core package does NOT bind
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
    policy: IdempotencyPolicy::PassThrough, // or Reject => 400 without key
    ttlSeconds: 3600,
);
```

With the Yii3 config plugin, tune via `params.php` under
`'rasuvaeff/yii3-idempotency'` (`headerName`, `policy`, `ttlSeconds`,
`methods`).

## Full API

The complete reference — every class, the fingerprint formula, storage
interface contract and config keys — ships with the package: read
`vendor/rasuvaeff/yii3-idempotency/llms.txt` before guessing a method name.
