<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads the key out of the parsed body with dot notation: `order.orderId`
 * resolves `['order' => ['orderId' => 'abc']]` to `abc`.
 *
 * Segments are matched literally, so a payload key that itself contains a dot
 * is not addressable.
 *
 * @api
 */
final readonly class PayloadIdempotencyKeyExtractor implements IdempotencyKeyExtractor
{
    /**
     * @var non-empty-list<non-empty-string>
     */
    private array $segments;

    /**
     * @param string $dotPath dot-separated path into the parsed body, e.g. `command.orderId`
     * @param bool $required whether a missing value throws {@see MissingKeyException} instead of
     *                       resolving to `null` (which hands the request to the middleware policy)
     */
    public function __construct(
        private string $dotPath,
        private bool $required = true,
    ) {
        $segments = explode('.', $dotPath);

        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new \InvalidArgumentException(
                    sprintf('Invalid payload path "%s": segments must not be empty', $dotPath),
                );
            }
        }

        /** @var non-empty-list<non-empty-string> $segments */
        $this->segments = $segments;
    }

    #[\Override]
    public function extract(ServerRequestInterface $request): ?IdempotencyKey
    {
        $value = $this->resolve($request->getParsedBody());

        if ($value === null) {
            if ($this->required) {
                throw new MissingKeyException(
                    sprintf('No idempotency key at payload path "%s"', $this->dotPath),
                );
            }

            return null;
        }

        return new IdempotencyKey($value);
    }

    private function resolve(mixed $payload): ?string
    {
        $current = $payload;

        foreach ($this->segments as $segment) {
            if (!\is_array($current) || !\array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        if (\is_string($current)) {
            return $current;
        }

        if (\is_int($current)) {
            return (string) $current;
        }

        return null;
    }
}
