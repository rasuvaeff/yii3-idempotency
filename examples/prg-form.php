<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3Idempotency\HeaderIdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\IdempotencyMiddleware;
use Rasuvaeff\Yii3Idempotency\InMemoryIdempotencyStorage;
use Rasuvaeff\Yii3Idempotency\SharedKeyspaceScopeResolver;

$clock = new class () implements ClockInterface {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
    }
};

/**
 * A Post/Redirect/Get form endpoint: appends an entry, then redirects to the
 * page that shows the result.
 */
final class GuestbookForm implements RequestHandlerInterface
{
    public int $submissions = 0;

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->submissions++;

        return (new Response(303))->withHeader('Location', '/guestbook?page=1');
    }
}

function formRequest(): ServerRequestInterface
{
    return new ServerRequest('POST', '/guestbook', ['Idempotency-Key' => 'gb-42'], 'message=hello');
}

function middleware(?array $cacheableRedirectStatuses): IdempotencyMiddleware
{
    global $clock;

    return new IdempotencyMiddleware(
        keyExtractor: new HeaderIdempotencyKeyExtractor(),
        storage: new InMemoryIdempotencyStorage($clock),
        responseFactory: new Psr17Factory(),
        clock: $clock,
        // a single trusted client behind the form — see "Caller scoping" in the README
        scopeResolver: new SharedKeyspaceScopeResolver(),
        cacheableRedirectStatuses: $cacheableRedirectStatuses ?? [],
    );
}

echo "== Default: a 303 is not the outcome ==\n";

$default = middleware(null);
$handler = new GuestbookForm();

$first = $default->process(formRequest(), $handler);
$resubmit = $default->process(formRequest(), $handler);

echo "First answer:    {$first->getStatusCode()} -> {$first->getHeaderLine('Location')}\n";
echo "Resubmit answer: {$resubmit->getStatusCode()} -> {$resubmit->getHeaderLine('Location')}\n";
echo "Entries written: {$handler->submissions} — the form ran twice\n";

echo "\n== Opt-in: cacheableRedirectStatuses: [303] ==\n";

$optIn = middleware([303]);
$handler = new GuestbookForm();

$first = $optIn->process(formRequest(), $handler);
$resubmit = $optIn->process(formRequest(), $handler);

echo "First answer:    {$first->getStatusCode()} -> {$first->getHeaderLine('Location')}"
    . " (Idempotency-Replay: {$first->getHeaderLine('Idempotency-Replay')})\n";
echo "Resubmit answer: {$resubmit->getStatusCode()} -> {$resubmit->getHeaderLine('Location')}"
    . " (Idempotency-Replay: {$resubmit->getHeaderLine('Idempotency-Replay')})\n";
echo "Entries written: {$handler->submissions} — the replay never re-ran the form\n";

echo "\n== Only 3xx statuses are listable ==\n";

try {
    middleware([200]);
} catch (InvalidArgumentException $e) {
    echo "Rejected: {$e->getMessage()}\n";
}
