# rasuvaeff/yii3-idempotency

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-idempotency.svg?label=stable)](https://packagist.org/packages/rasuvaeff/yii3-idempotency)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-idempotency.svg)](https://packagist.org/packages/rasuvaeff/yii3-idempotency)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-idempotency/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-idempotency/actions)
[![Static analysis](https://img.shields.io/badge/psalm-level-1-blue)](https://psalm.dev)
[![Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen)](https://github.com/rasuvaeff/yii3-idempotency)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-idempotency/php)](https://packagist.org/packages/rasuvaeff/yii3-idempotency)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-idempotency.svg)](https://github.com/rasuvaeff/yii3-idempotency/blob/master/LICENSE.md)
[English version](README.md)

Middleware ключа идемпотентности для Yii3 API. Предотвращает повторную обработку POST/PUT/PATCH-запросов.

> Используете AI-ассистента? В [llms.txt](llms.txt) — компактный API-справочник, который можно передать модели.

## Требования

- PHP 8.3+
- `psr/clock` ^1.0
- `psr/http-message` ^2.0
- `psr/http-server-middleware` ^1.0

## Установка

```bash
composer require rasuvaeff/yii3-idempotency
```

## Использование

### Базовая настройка

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

### Как это работает

| Сценарий | Результат |
|---|---|
| Нет ключа идемпотентности, политика `PassThrough` | Запрос проходит дальше |
| Нет ключа идемпотентности, политика `Reject` | 400 Bad Request |
| Первый запрос с ключом | Обработчик выполняется, ответ сохраняется |
| Тот же ключ + тот же payload | Воспроизводится сохранённый ответ (обработчик не вызывается) |
| Тот же ключ + другой payload | 422 Unprocessable Content |
| Тот же ключ во время обработки первого запроса | 409 Conflict |
| Ответ обработчика не 2xx (3xx/4xx/5xx) | Ответ НЕ сохраняется — захват освобождается, клиент может повторить запрос с тем же ключом |
| Не сконфигурированный метод (например `GET`, `DELETE`) | Проходит нетронутым — идемпотентность применяется только к `methods` (по умолчанию POST/PUT/PATCH) |
| Запись с истёкшим TTL | Запрос обрабатывается как новый |

### Конфигурация

```php
// config/params.php
return [
    'rasuvaeff/yii3-idempotency' => [
        'headerName' => 'Idempotency-Key',
        'policy' => 'pass_through', // or 'reject'
        'ttlSeconds' => 3600,
        'methods' => ['POST', 'PUT', 'PATCH'], // methods idempotency applies to
    ],
];
```

## Публичный API

| Класс | Описание |
|---|---|
| `IdempotencyMiddleware` | PSR-15 middleware |
| `IdempotencyKey` | Валидируемый value object ключа (1-255 символов, `[A-Za-z0-9._-]+`) |
| `IdempotencyFingerprint` | Fingerprint запроса (method + path + query + хеш body) |
| `IdempotencyRecord` | Сохраняемая запись с TTL |
| `IdempotencyResponse` | Захваченный ответ (status, headers, body) |
| `IdempotencyStorage` | Интерфейс: load, claim, store, release |
| `IdempotencyKeyExtractor` | Интерфейс стратегий извлечения ключа |
| `InMemoryIdempotencyStorage` | In-memory реализация (для тестов) |
| `HeaderIdempotencyKeyExtractor` | Извлекает ключ из заголовка запроса |
| `IdempotencyPolicy` | Enum: `PassThrough`, `Reject` |

## Безопасность

- Fingerprint включает method, path, query string и body — предотвращает подмену payload
- Stream тела запроса перематывается после снятия fingerprint — обработчики могут читать его повторно
- Кэшируются только 2xx-ответы; не-2xx (включая повторяемые 409/423/429 и любые 5xx) освобождают захват, поэтому временный сбой не воспроизводится весь TTL
- Идемпотентность применяется только к сконфигурированным `methods` (по умолчанию POST/PUT/PATCH) — безопасные методы проходят дальше
- Атомарный claim предотвращает состояния гонки (в персистентных storage-адаптерах)
- TTL предотвращает бессрочное хранение

## Примеры

См. [`examples/`](examples/) — запускаемые скрипты.

## Разработка

```bash
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` и `make mutation` поднимают `pcov` внутри контейнера
`composer:2`, потому что в базовом образе нет драйвера покрытия.

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
