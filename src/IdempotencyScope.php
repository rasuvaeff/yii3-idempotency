<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

use Psr\Http\Message\ServerRequestInterface;

/**
 * A namespace for idempotency keys. Two endpoints that share a key value but
 * not a scope get independent storage records instead of colliding on one.
 *
 * A scope is also a resolver of itself, so it can be handed straight to
 * {@see ScopedIdempotencyKeyExtractor} when the scope is static.
 *
 * @api
 */
final readonly class IdempotencyScope implements IdempotencyScopeResolver
{
    private const int MIN_LENGTH = 1;

    private const int MAX_LENGTH = 1024;

    /**
     * Anything printable: the name is never stored or echoed, only hashed, so
     * only control characters (which would come from a malformed request
     * target rather than a real one) are rejected.
     */
    private const string PATTERN = '/^[^\x00-\x1F\x7F]+\z/';

    public string $name;

    public function __construct(string $name)
    {
        $length = strlen($name);

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(
                'Idempotency scope must be between 1 and 1024 characters',
            );
        }

        if (!preg_match(self::PATTERN, $name)) {
            throw new \InvalidArgumentException(
                'Idempotency scope contains control characters',
            );
        }

        $this->name = $name;
    }

    /**
     * The storage key for this scope. Hashing rather than prefixing keeps the
     * result at a fixed 64 characters: a prefix would push a long-but-valid
     * client key past the 255-character limit and reject a request that used
     * to work.
     */
    public function apply(IdempotencyKey $key): IdempotencyKey
    {
        return new IdempotencyKey(hash('sha256', $this->name . "\0" . $key->value));
    }

    #[\Override]
    public function resolve(ServerRequestInterface $request): self
    {
        return $this;
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name;
    }
}
