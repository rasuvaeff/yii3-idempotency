# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
