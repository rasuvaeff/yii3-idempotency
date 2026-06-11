# rasuvaeff/yii3-idempotency

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-idempotency.svg?label=stable)](https://packagist.org/packages/rasuvaeff/yii3-idempotency)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-idempotency.svg)](https://packagist.org/packages/rasuvaeff/yii3-idempotency)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-idempotency/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-idempotency/actions)
[![Static analysis](https://img.shields.io/badge/psalm-level-1-blue)](https://psalm.dev)
[![Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen)](https://github.com/rasuvaeff/yii3-idempotency)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-idempotency/php)](https://packagist.org/packages/rasuvaeff/yii3-idempotency)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-idempotency.svg)](https://github.com/rasuvaeff/yii3-idempotency/blob/master/LICENSE.md)

Idempotency key middleware for Yii3 APIs. Prevents duplicate processing of POST/PUT/PATCH requests.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can feed to the LLM.

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

$middleware = new IdempotencyMiddleware(
    keyExtractor: new HeaderIdempotencyKeyExtractor(),
    storage: new InMemoryIdempotencyStorage($clock),
    responseFactory: $responseFactory,
    clock: $clock,
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
| Handler responds with 5xx | Response NOT stored — client may retry with the same key |
| Expired record | Request processed as new |

### Configuration

```php
// config/params.php
return [
    'rasuvaeff/yii3-idempotency' => [
        'headerName' => 'Idempotency-Key',
        'policy' => 'pass_through', // or 'reject'
        'ttlSeconds' => 3600,
    ],
];
```

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
| `IdempotencyPolicy` | Enum: `PassThrough`, `Reject` |

## Security

- Fingerprint includes method, path, query string, and body — prevents payload substitution
- Request body stream is rewound after fingerprinting — handlers can re-read it
- 5xx responses are never stored, so a server failure cannot be replayed for the whole TTL
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
