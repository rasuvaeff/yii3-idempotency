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

        self::assertPrintable($name);

        $this->name = $name;
    }

    /**
     * Like the constructor, but collapses an over-long name to its hash instead
     * of rejecting it.
     *
     * A scope name assembled from request data — a long path, a long principal
     * identifier, several dimensions joined together — must not turn a request
     * into a 500 just for crossing the limit. The name is only ever hashed into
     * a storage key, never stored or echoed, so a collapsed name partitions the
     * keyspace exactly as well as the original.
     *
     * Length is the only relaxation: a control character is rejected at every
     * length, because hashing must not launder a name the constructor refuses.
     */
    public static function of(string $name): self
    {
        if (\strlen($name) <= self::MAX_LENGTH) {
            return new self($name);
        }

        self::assertPrintable($name);

        return new self(hash('sha256', $name));
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

    private static function assertPrintable(string $name): void
    {
        if (!preg_match(self::PATTERN, $name)) {
            throw new \InvalidArgumentException(
                'Idempotency scope contains control characters',
            );
        }
    }
}
