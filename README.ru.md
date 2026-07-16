# rasuvaeff/yii3-идемпотентность
[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-idempotency.svg?label=stable)](https://packagist.org/packages/rasuvaeff/yii3-idempotency)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-idempotency.svg)](https://packagist.org/packages/rasuvaeff/yii3-idempotency)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-idempotency/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-idempotency/actions)
[![Static analysis](https://img.shields.io/badge/psalm-level-1-blue)](https://psalm.dev)
[![Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen)](https://github.com/rasuvaeff/yii3-idempotency)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-idempotency/php)](https://packagist.org/packages/rasuvaeff/yii3-idempotency)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-idempotency.svg)](https://github.com/rasuvaeff/yii3-idempotency/blob/master/LICENSE.md)
Промежуточное программное обеспечение ключа идемпотентности для API Yii3. Предотвращает дублирующую обработку запросов POST/PUT/PATCH.

 > Используете помощника по программированию с искусственным интеллектом? [llms.txt](llms.txt) содержит компактную ссылку на API, которую вы можете передать в LLM. @@ЛИНИЯ@@
## Требования
- PHP 8.3+
 - `psr/lock` ^1.0
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
 | Нет ключа идемпотентности, политика PassThrough | Запрос проходит через |
 | Нет ключа идемпотентности, политика «Отклонить» | 400 неверный запрос |
 | Первый запрос с ключом | Процессы-обработчики, ответ сохраняется |
 | Тот же ключ + та же полезная нагрузка | Сохраненный ответ воспроизводится (обработчик не вызывается) |
 | Тот же ключ + другая полезная нагрузка | 422 Необработанный контент |
 | Тот же ключ, пока первый запрос все еще обрабатывается | 409 Конфликт |
 | Ответ обработчика, отличного от 2xx (3xx/4xx/5xx) | Ответ НЕ сохранен — заявка отозвана, клиент может повторить попытку с тем же ключом |
 | Ненастроенный метод (например, GET, DELETE) | Проходит без изменений — идемпотентность применяется только к `методам` (по умолчанию POST/PUT/PATCH) |
 | Запись с истекшим сроком действия | Запрос обработан как новый | @@ЛИНИЯ@@
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
 | `ИдемпотентностьMiddleware` | Промежуточное программное обеспечение PSR-15 |
 | `Идемпотентный ключ` | Проверенный объект значения ключа (1–255 символов, `[A-Za-z0-9._-]+`) |
 | `ИдемпотентностьFingerprint` | Отпечаток запроса (метод + путь + запрос + хеш тела) |
 | `ИдемпотентностьRecord` | Сохраненная запись с TTL |
 | `ИдемпотентныйОтвет` | Захваченный ответ (статус, заголовки, тело) |
 | `Идемпотентное хранилище` | Интерфейс: загрузка, запрос, сохранение, выпуск |
 | `IdempotencyKeyExtractor` | Интерфейс для стратегий извлечения ключей |
 | `InMemoryIdempotencyStorage` | Реализация в памяти (для тестирования) |
 | `HeaderIdempotencyKeyExtractor` | Извлекает ключ из заголовка запроса |
 | `ИдемпотентиПолици` | Перечисление: `PassThrough`, `Отклонить` | @@ЛИНИЯ@@
## Безопасность
- Отпечаток включает в себя метод, путь, строку запроса и тело — предотвращает подмену полезной нагрузки
 — Поток тела запроса перематывается после снятия отпечатка — обработчики могут его перечитать
 — Кэшируются только 2xx ответов; не-2xx (включая повторяемые 409/423/429 и любые 5xx) освобождают утверждение, поэтому временный сбой не может быть воспроизведен для всего TTL
 - Идемпотентность применяется только к настроенным `методам` (POST/PUT/PATCH по умолчанию) – безопасные методы проходят через
 - Атомарное утверждение предотвращает условия гонки (в адаптерах постоянного хранилища)
 - TTL предотвращает неопределенное хранение

## Примеры
См. [`examples/`](examples/) для ознакомления с работоспособными скриптами. @@ЛИНИЯ@@
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
`make test-coverage` и `makemutation` загружают `pcov` внутри контейнера
 `composer:2`, поскольку базовый образ не имеет драйвера покрытия. @@ЛИНИЯ@@
## Лицензия
BSD-3-пункт. См. [LICENSE.md](LICENSE.md).
