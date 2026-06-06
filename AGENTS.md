# AGENTS.md — yii3-idempotency

Guidance for AI agents working on this package. Read before changing code.

## What this is

Idempotency key PSR-15 middleware for Yii3 APIs. Prevents duplicate processing of POST/PUT/PATCH
requests by storing and replaying responses. Supports atomic claim, conflict detection (different
payload, same key), and TTL-based expiration.

Namespace: `Rasuvaeff\Yii3Idempotency`.
Public API: `IdempotencyMiddleware`, `IdempotencyKey`, `IdempotencyFingerprint`, `IdempotencyRecord`,
`IdempotencyResponse`, `IdempotencyStorage`, `InMemoryIdempotencyStorage`,
`HeaderIdempotencyKeyExtractor`, `IdempotencyPolicy`.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Atomic claim.** Storage MUST support atomic claim. `InMemoryIdempotencyStorage`
   is a testing double — production uses persistent adapters.
4. **Preserve the public contract.** Update README + tests with any API change.

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

- `IdempotencyKey` validates: 1-255 chars, pattern `/^[A-Za-z0-9._-]+$/`.
- Fingerprint: `sha256(method + "\n" + path + "\n" + body)`.
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
