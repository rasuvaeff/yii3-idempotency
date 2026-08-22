<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Derives the scope from the authenticated caller carried in a request
 * attribute, so an idempotency key belongs to the client that sent it.
 *
 * Without this partitioning a client that knows (or guesses) another client's
 * key and reproduces its payload gets that client's cached response replayed —
 * and the replay path never reaches the handler, so it never reaches the
 * handler's authorization checks either.
 *
 * A request that carries no caller resolves to a namespace of its own, built
 * from {@see self::DEFAULT_ANONYMOUS} and never reachable by an authenticated
 * caller. Anonymous callers do share that one namespace among themselves: there
 * is no identity to separate them by. Do not expose an endpoint that returns
 * caller-private data to anonymous requests under an idempotency key.
 *
 * @api
 */
final readonly class RequestAttributeScopeResolver implements IdempotencyScopeResolver
{
    public const string DEFAULT_ATTRIBUTE = 'user';

    public const string DEFAULT_ANONYMOUS = 'anonymous';

    /**
     * Marks the scope as a caller namespace, so a composite that also carries an
     * endpoint scope cannot produce the same name from a different dimension.
     */
    private const string PREFIX = 'caller:';

    /**
     * The caller state is part of the name, not only the identity: without it an
     * authenticated caller whose identity happens to equal {@see $anonymous}
     * lands in the namespace every unauthenticated request already shares, and
     * either side can replay or occupy the other's record.
     */
    private const string IDENTITY_TAG = 'identity:';

    private const string ANONYMOUS_TAG = 'anonymous:';

    /**
     * @var non-empty-string
     */
    private string $attribute;

    /**
     * @var non-empty-string
     */
    private string $anonymous;

    /**
     * @param string $attribute request attribute holding the authenticated principal
     * @param string $anonymous scope name used when the attribute is absent or empty
     * @param (\Closure(mixed): (string|int|\Stringable|null))|null $identity maps the attribute value to an
     *                                                                       identity; needed when the attribute
     *                                                                       holds a user object
     */
    public function __construct(
        string $attribute = self::DEFAULT_ATTRIBUTE,
        string $anonymous = self::DEFAULT_ANONYMOUS,
        private ?\Closure $identity = null,
    ) {
        if ($attribute === '') {
            throw new \InvalidArgumentException('Caller attribute name must not be empty');
        }

        if ($anonymous === '') {
            throw new \InvalidArgumentException('Anonymous scope name must not be empty');
        }

        $this->attribute = $attribute;
        $this->anonymous = $anonymous;
    }

    #[\Override]
    public function resolve(ServerRequestInterface $request): IdempotencyScope
    {
        $value = $request->getAttribute($this->attribute);

        if ($this->identity instanceof \Closure) {
            $value = ($this->identity)($value);
        }

        return IdempotencyScope::of(self::PREFIX . $this->identityName($value));
    }

    /**
     * @return non-empty-string
     */
    private function identityName(mixed $value): string
    {
        if ($value === null) {
            return self::ANONYMOUS_TAG . $this->anonymous;
        }

        if (\is_int($value)) {
            return self::IDENTITY_TAG . $value;
        }

        if (\is_string($value) || $value instanceof \Stringable) {
            $name = (string) $value;

            return $name === ''
                ? self::ANONYMOUS_TAG . $this->anonymous
                : self::IDENTITY_TAG . $name;
        }

        // Not client input: the application decides what it puts in the
        // attribute, so a wrong type is a deployment error and must be loud.
        throw new \InvalidArgumentException(sprintf(
            'Request attribute "%s" must hold a string, an int, a Stringable or null, got %s',
            $this->attribute,
            get_debug_type($value),
        ));
    }
}
