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
> Проекты с Composer-плагином [llm/skills](https://github.com/roxblnfk/skills) дополнительно получают agent-скилл этого пакета в `.agents/skills/` автоматически при установке.

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
| Обработчик бросил исключение, `DomainFailureRenderer` не задан | Захват освобождается, исключение пробрасывается — повтор снова выполняет обработчик |
| Обработчик бросил доменную ошибку, renderer задан | Отрендеренный ответ сохраняется и воспроизводится как успех (см. ниже) |
| Не сконфигурированный метод (например `GET`, `DELETE`) | Проходит нетронутым — идемпотентность применяется только к `methods` (по умолчанию POST/PUT/PATCH) |
| Запись с истёкшим TTL | Запрос обрабатывается как новый |

### Классификация ошибок

По умолчанию любое исключение освобождало захват, поэтому детерминированный
бизнес-исход (`PaymentDeclined`, `InsufficientFunds`) выполнялся повторно.
Передайте middleware `DomainFailureRenderer` — и доменные ошибки становятся
частью кэшируемого результата:

```php
use Rasuvaeff\Yii3Idempotency\DefaultFailureClassifier;
use Rasuvaeff\Yii3Idempotency\FailureKind;
use Rasuvaeff\Yii3Idempotency\IdempotencyMiddleware;

$middleware = new IdempotencyMiddleware(
    keyExtractor: new HeaderIdempotencyKeyExtractor(),
    storage: $storage,
    responseFactory: $responseFactory,
    clock: $clock,
    domainFailureRenderer: $renderer,                     // ваш DomainFailureRenderer
    failureClassifier: new DefaultFailureClassifier([     // необязательные переопределения
        MisconfiguredGatewayException::class => FailureKind::Bug,
    ]),
);
```

`DefaultFailureClassifier` решает по порядку:

| Throwable | Kind | Эффект |
|---|---|---|
| Совпал с явным переопределением (`instanceof`, порядок объявления) | как настроено | как ниже |
| Реализует `RetryableFailure` | `Infrastructure` | Захват освобождается, повтор снова выполняет обработчик |
| Любое другое `\Exception` | `Domain` | Рендерится, сохраняется, воспроизводится весь TTL |
| `\Error` и всё остальное | `Infrastructure` | Захват освобождается, повтор снова выполняет обработчик |

`FailureKind::Bug` никогда не выводится автоматически — объявляйте его
переопределением. `Bug` и `Infrastructure` одинаково освобождают захват и
пробрасывают исключение; различие нужно для вашей отчётности.

Middleware кэширует ровно то, что вернул renderer, поэтому первая попытка и
любое воспроизведение побайтово совпадают. Renderer, вернувший `null`,
отказывается от ошибки: захват освобождается, исходное исключение
пробрасывается. Без renderer'а ничего не меняется — любое исключение остаётся
повторяемым.

Классифицируется только путь с исключением. Обработчик, *вернувший* 4xx-ответ,
по-прежнему освобождает захват.

### Скоупы ключей

Голый ключ идентифицирует запрос, но не эндпоинт, поэтому один и тот же ключ,
отправленный на два эндпоинта, попадает в одну запись. Скоуп задаёт ему
пространство имён:

```php
use Rasuvaeff\Yii3Idempotency\IdempotencyScope;
use Rasuvaeff\Yii3Idempotency\RequestTargetScopeResolver;
use Rasuvaeff\Yii3Idempotency\ScopedIdempotencyKeyExtractor;

// 'auto' — своё пространство имён на каждый "METHOD /path"
$extractor = new ScopedIdempotencyKeyExtractor(
    extractor: new HeaderIdempotencyKeyExtractor(),
    scopeResolver: new RequestTargetScopeResolver(),
);

// явный — связанные эндпоинты делят одно пространство имён
$extractor = new ScopedIdempotencyKeyExtractor(
    extractor: new HeaderIdempotencyKeyExtractor(),
    scopeResolver: new IdempotencyScope('orders'),
);
```

Ключ хранения становится `sha256(scope . "\0" . key)` — фиксированные 64
символа, поэтому длинный, но валидный клиентский ключ невозможно вытолкнуть за
предел в 255 символов. Как следствие, сохранённые ключи непрозрачны: скоупы
меняют читаемость ключей на отсутствие коллизий. Не задавайте скоуп — и ключи
останутся глобальными и сохранятся как есть.

### Ключи из payload

В обработчиках очередей и command-bus ключ лежит внутри payload, а не в
заголовке:

```php
use Rasuvaeff\Yii3Idempotency\PayloadIdempotencyKeyExtractor;

$extractor = new PayloadIdempotencyKeyExtractor('command.orderId');
```

Путь читается из распарсенного тела в dot-нотации. Сегменты сопоставляются
буквально, поэтому до ключа payload с точкой в имени не добраться. Значение,
которое не разрешилось (отсутствует, `null` или не строка/int), приводит к
`MissingKeyException`; передайте `required: false`, чтобы вместо этого получить
`null` и отдать решение политике middleware.

### Конфигурация

```php
// config/params.php
return [
    'rasuvaeff/yii3-idempotency' => [
        'headerName' => 'Idempotency-Key',
        'policy' => 'pass_through', // or 'reject'
        'ttlSeconds' => 3600,
        'methods' => ['POST', 'PUT', 'PATCH'], // methods idempotency applies to
        'scope' => null, // null — глобально; 'auto' — по "METHOD /path"; любая другая строка — явное имя скоупа
    ],
];
```

У `DomainFailureRenderer` и `FailureClassifier` нет биндинга по умолчанию — это
зона ответственности приложения. Регистрируйте их в своём
`config/common/di/*.php`, когда нужно кэширование доменных ошибок.

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
| `PayloadIdempotencyKeyExtractor` | Извлекает ключ из распарсенного тела по dot-пути |
| `ScopedIdempotencyKeyExtractor` | Декоратор, задающий извлечённому ключу пространство имён скоупа |
| `IdempotencyScope` | Валидируемое имя скоупа; сам себе resolver |
| `IdempotencyScopeResolver` | Интерфейс разрешения скоупа по запросу |
| `RequestTargetScopeResolver` | Выводит скоуп из `METHOD /path` |
| `IdempotencyPolicy` | Enum: `PassThrough`, `Reject` |
| `FailureKind` | Enum: `Domain`, `Infrastructure`, `Bug` |
| `FailureClassifier` | Интерфейс: классифицирует throwable в `FailureKind` |
| `DefaultFailureClassifier` | Классификатор по типу/маркеру с явными переопределениями |
| `RetryableFailure` | Маркер-интерфейс для исключений, которые может разрешить повтор |
| `DomainFailureRenderer` | Интерфейс: рендерит доменную ошибку в кэшируемый ответ |
| `MissingKeyException` | Бросается, когда обязательный ключ отсутствует в запросе |

## Безопасность

- Fingerprint включает method, path, query string и body — предотвращает подмену payload
- Stream тела запроса перематывается после снятия fingerprint — обработчики могут читать его повторно
- Кэшируются только 2xx-ответы; не-2xx (включая повторяемые 409/423/429 и любые 5xx) освобождают захват, поэтому временный сбой не воспроизводится весь TTL
- Брошенные исключения кэшируются, только если настроен `DomainFailureRenderer` **и** классификатор назвал их `Domain`. `\Error`, всё помеченное `RetryableFailure` и всё, что вы переопределили как `Bug`, всегда освобождают захват — временный сбой по-прежнему невозможно закрепить на весь TTL. Классифицируйте консервативно: ошибка, закэшированная как `Domain`, воспроизводится до истечения записи
- Скоуп хеширует клиентский ключ вместе с именем скоупа, поэтому имя скоупа никогда не возвращается наружу, а длинный ключ не может выйти за предел длины
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
