# rasuvaeff/yii3-idempotency

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-idempotency.svg?label=stable)](https://packagist.org/packages/rasuvaeff/yii3-idempotency)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-idempotency.svg)](https://packagist.org/packages/rasuvaeff/yii3-idempotency)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-idempotency/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-idempotency/actions)
[![Static analysis](https://img.shields.io/badge/psalm-level-1-blue)](https://psalm.dev)
[![Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen)](https://github.com/rasuvaeff/yii3-idempotency)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-idempotency/php)](https://packagist.org/packages/rasuvaeff/yii3-idempotency)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-idempotency.svg)](https://github.com/rasuvaeff/yii3-idempotency/blob/master/LICENSE.md)
[Русская версия](README.ru.md)

Idempotency key middleware for Yii3 APIs. Prevents duplicate processing of POST/PUT/PATCH requests.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can feed to the LLM.
> Projects using the [llm/skills](https://github.com/roxblnfk/skills) Composer plugin also get this package's agent skill synced into `.agents/skills/` automatically on install.

## Requirements

- PHP 8.3+
- `psr/clock` ^1.0
- `psr/http-message` ^2.0
- `psr/http-server-middleware` ^1.0

## Installation

```bash
composer require rasuvaeff/yii3-idempotency
```

## Usage

### Basic setup

```php
use Rasuvaeff\Yii3Idempotency\HeaderIdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\IdempotencyMiddleware;
use Rasuvaeff\Yii3Idempotency\InMemoryIdempotencyStorage;
use Rasuvaeff\Yii3Idempotency\RequestAttributeScopeResolver;

$middleware = new IdempotencyMiddleware(
    keyExtractor: new HeaderIdempotencyKeyExtractor(),
    storage: new InMemoryIdempotencyStorage($clock),
    responseFactory: $responseFactory,
    clock: $clock,
    // required: who a key belongs to. See "Caller scoping" below
    scopeResolver: new RequestAttributeScopeResolver(attribute: 'user'),
    ttlSeconds: 3600,
);
```

### How it works

| Scenario | Result |
|---|---|
| No idempotency key, `PassThrough` policy | Request passes through |
| No idempotency key, `Reject` policy | 400 Bad Request |
| First request with key | Handler processes, response stored |
| Same key + same payload | Stored response replayed (handler not called) |
| Same key + different payload | 422 Unprocessable Content |
| Same key while first request is still processing | 409 Conflict |
| Malformed key (too long, illegal characters) | 400 Bad Request |
| Same key + same payload, different caller | Handler processes again — the two callers never share a record |
| Non-2xx handler response (3xx/4xx/5xx) | Response NOT stored — claim released, client may retry with the same key |
| Handler throws, no `DomainFailureRenderer` | Claim released, throwable rethrown — retry re-runs the handler |
| Handler throws a domain failure, renderer configured | Rendered response stored and replayed like a success (see below) |
| Non-configured method (e.g. `GET`, `DELETE`) | Passes through untouched — idempotency applies only to `methods` (default POST/PUT/PATCH) |
| Expired record | Request processed as new |

### Failure classification

By default every throwable released the claim, so a deterministic business
outcome (`PaymentDeclined`, `InsufficientFunds`) was re-executed on retry. Give
the middleware a `DomainFailureRenderer` and domain failures become part of the
cached outcome instead:

```php
use Rasuvaeff\Yii3Idempotency\DefaultFailureClassifier;
use Rasuvaeff\Yii3Idempotency\FailureKind;
use Rasuvaeff\Yii3Idempotency\IdempotencyMiddleware;

$middleware = new IdempotencyMiddleware(
    keyExtractor: new HeaderIdempotencyKeyExtractor(),
    storage: $storage,
    responseFactory: $responseFactory,
    clock: $clock,
    scopeResolver: new RequestAttributeScopeResolver(attribute: 'user'),
    domainFailureRenderer: $renderer,                     // your DomainFailureRenderer
    failureClassifier: new DefaultFailureClassifier([     // optional overrides
        MisconfiguredGatewayException::class => FailureKind::Bug,
    ]),
);
```

`DefaultFailureClassifier` decides, in order:

| Throwable | Kind | Effect |
|---|---|---|
| Matches an explicit override (`instanceof`, declaration order) | as configured | as below |
| Implements `RetryableFailure` | `Infrastructure` | Claim released, retry re-runs the handler |
| Any other `\Exception` | `Domain` | Rendered, stored, replayed for the whole TTL |
| `\Error` and everything else | `Infrastructure` | Claim released, retry re-runs the handler |

`FailureKind::Bug` is never inferred — declare it via an override. Both `Bug` and
`Infrastructure` release the claim and rethrow; the distinction is for your own
reporting.

The middleware caches exactly what the renderer returns, so the first attempt and
every replay are byte-identical. A renderer that returns `null` declines the
failure: the claim is released and the original throwable is rethrown. Without a
renderer nothing changes — every throwable stays retryable.

Only the throwing path is classified. A handler that *returns* a 4xx response
still releases the claim.

### Caller scoping

`scopeResolver` has no default, on purpose. A keyspace shared by every caller
lets one client replay another client's cached response — and the replay path
returns the stored response without ever entering the handler, so it never
reaches the handler's authorization checks either. The same gap lets a client
occupy someone else's key and lock them out for the whole TTL.

```php
use Rasuvaeff\Yii3Idempotency\RequestAttributeScopeResolver;

// the authenticated principal your auth middleware put in the request attribute
scopeResolver: new RequestAttributeScopeResolver(attribute: 'user');

// the attribute holds a user object rather than an identifier
scopeResolver: new RequestAttributeScopeResolver(
    attribute: 'user',
    identity: static fn (mixed $user): ?int => $user?->getId(),
);
```

A request with no principal resolves to the `anonymous` namespace, which every
anonymous caller shares — there is no identity to separate them by. Do not put
caller-private data behind an idempotent endpoint reachable anonymously.

**Opt-out.** `SharedKeyspaceScopeResolver` puts every caller in one keyspace,
which is the pre-2.0 behaviour. It is safe only when a single principal can
reach the middleware — a single-tenant deployment, an internal service with one
trusted client, or an endpoint whose responses hold nothing caller-private.

```php
use Rasuvaeff\Yii3Idempotency\SharedKeyspaceScopeResolver;

scopeResolver: new SharedKeyspaceScopeResolver();
```

### Endpoint scoping

A key also identifies the request but not the endpoint, so the same key sent to
two endpoints would collide on one record. `CompositeScopeResolver` stacks the
endpoint namespace on top of the caller:

```php
use Rasuvaeff\Yii3Idempotency\CompositeScopeResolver;
use Rasuvaeff\Yii3Idempotency\IdempotencyScope;
use Rasuvaeff\Yii3Idempotency\RequestTargetScopeResolver;

// caller + "METHOD /path" — the wiring the bundled config builds by default
scopeResolver: new CompositeScopeResolver(
    new RequestAttributeScopeResolver(attribute: 'user'),
    new RequestTargetScopeResolver(),
);

// caller + an explicit namespace shared by related endpoints
scopeResolver: new CompositeScopeResolver(
    new RequestAttributeScopeResolver(attribute: 'user'),
    new IdempotencyScope('orders'),
);
```

The storage key becomes `sha256(scope . "\0" . key)` — a fixed 64 characters, so
a long-but-valid client key can never be pushed past the 255-character limit.
Stored keys are therefore opaque: scoping trades greppable keys for collision
freedom. A scope name that would itself exceed 1024 characters (a long path, a
long principal identifier, several dimensions joined) is collapsed to its hash
rather than rejected, so request data can never turn into a 500.

`ScopedIdempotencyKeyExtractor` still applies a scope at the extractor level and
is kept for compatibility, but scoping the middleware is the supported way: it
is the one place that cannot be left out.

### Keys from the payload

For queue handlers and command-bus consumers the key lives inside the payload
rather than in a header:

```php
use Rasuvaeff\Yii3Idempotency\PayloadIdempotencyKeyExtractor;

$extractor = new PayloadIdempotencyKeyExtractor('command.orderId');
```

The path is read from the parsed body with dot notation. Segments are matched
literally, so a payload key containing a dot is not addressable. A value that
resolves to nothing (missing, `null`, or not a string/int) throws
`MissingKeyException`; pass `required: false` to resolve to `null` instead and
let the middleware policy decide.

### Configuration

```php
// config/params.php
return [
    'rasuvaeff/yii3-idempotency' => [
        'headerName' => 'Idempotency-Key',
        'policy' => 'pass_through', // or 'reject'
        'ttlSeconds' => 3600,
        'methods' => ['POST', 'PUT', 'PATCH'], // methods idempotency applies to
        // REQUIRED — the container refuses to build the middleware while this is null.
        // A request-attribute name: keys are namespaced by the principal found there.
        // false: every caller shares one keyspace (pre-2.0 behaviour) — see "Caller scoping".
        'callerAttribute' => 'user',
        'anonymousCaller' => 'anonymous', // namespace for requests carrying no principal
        'scope' => 'auto', // 'auto' — per "METHOD /path"; null — one namespace for every endpoint; any other string — explicit scope name
    ],
];
```

`DomainFailureRenderer` has no default binding — it is an application concern, and
wiring one in your own `config/common/di/*.php` is what turns domain-failure
caching on. `FailureClassifier` needs wiring only to override
`DefaultFailureClassifier`, which the middleware falls back to on its own.

## Public API

| Class | Description |
|---|---|
| `IdempotencyMiddleware` | PSR-15 middleware |
| `IdempotencyKey` | Validated key value object (1-255 chars, `[A-Za-z0-9._-]+`) |
| `IdempotencyFingerprint` | Request fingerprint (method + path + query + body hash) |
| `IdempotencyRecord` | Stored record with TTL |
| `IdempotencyResponse` | Captured response (status, headers, body) |
| `IdempotencyStorage` | Interface: load, claim, store, release |
| `IdempotencyKeyExtractor` | Interface for key extraction strategies |
| `InMemoryIdempotencyStorage` | In-memory implementation (for testing) |
| `HeaderIdempotencyKeyExtractor` | Extracts key from request header |
| `PayloadIdempotencyKeyExtractor` | Extracts key from the parsed body by dot path |
| `ScopedIdempotencyKeyExtractor` | Decorator namespacing the extracted key with a scope |
| `IdempotencyScope` | Validated scope name; also resolves to itself. `of()` collapses an over-long name to its hash |
| `IdempotencyScopeResolver` | Interface for per-request scope resolution |
| `RequestAttributeScopeResolver` | Namespaces the key by the authenticated principal in a request attribute |
| `SharedKeyspaceScopeResolver` | Puts every caller in one keyspace — the documented opt-out |
| `CompositeScopeResolver` | Joins several scope dimensions into one |
| `RequestTargetScopeResolver` | Derives the scope from `METHOD /path` |
| `IdempotencyPolicy` | Enum: `PassThrough`, `Reject` |
| `FailureKind` | Enum: `Domain`, `Infrastructure`, `Bug` |
| `FailureClassifier` | Interface: classifies a throwable into a `FailureKind` |
| `DefaultFailureClassifier` | Marker/type-based classifier with explicit overrides |
| `RetryableFailure` | Marker interface for exceptions a retry may resolve |
| `DomainFailureRenderer` | Interface: renders a domain failure into a cacheable response |
| `MissingKeyException` | Thrown when a required key is absent from the request |

## Security

- Keys are namespaced by the caller: `scopeResolver` is a required constructor argument, so a keyspace shared across clients is a deliberate, documented choice (`SharedKeyspaceScopeResolver`) and never an accident. Without it one client can replay another client's cached response, and the replay path never enters the handler — never reaching its authorization checks
- `Set-Cookie`, `Date` and hop-by-hop response headers are never captured, so a session identifier does not end up in a storage row for the whole TTL and a stale cookie is never replayed. The list is a constructor argument if you need more
- A malformed key from an untrusted request answers 400, not 500 — a client cannot generate unhandled exceptions with one header
- Fingerprint includes method, path, query string, and body — prevents payload substitution
- Request body stream is rewound after fingerprinting — handlers can re-read it
- Only 2xx responses **returned by the handler** are cached; non-2xx (incl. retryable 409/423/429 and any 5xx) release the claim, so a transient failure cannot be replayed for the whole TTL. A rendered domain failure is the one deliberate exception and is cached at whatever status the renderer chose
- Thrown failures are cached only when you configure a `DomainFailureRenderer` **and** the classifier calls them `Domain`. `\Error`, anything marked `RetryableFailure`, and anything you override as `Bug` always release the claim — a transient failure still cannot be pinned for the whole TTL. Classify conservatively: a failure cached as `Domain` is replayed until the record expires
- Scoping hashes the client key together with the scope, so a scope name is never echoed back and a long key cannot be pushed past the length limit
- Idempotency applies only to the configured `methods` (default POST/PUT/PATCH) — safe methods pass through
- Atomic claim prevents race conditions (in persistent storage adapters)
- TTL prevents indefinite storage

## Examples

See [`examples/`](examples/) for runnable scripts.

## Development

```bash
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
