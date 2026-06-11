<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Psr\Http\Message\UriInterface;

/**
 * @internal
 */
final class FakeUri implements UriInterface
{
    public function __construct(
        private readonly string $path = '/',
        private readonly string $query = '',
    ) {}

    #[\Override]
    public function getScheme(): string
    {
        return 'https';
    }

    #[\Override]
    public function getAuthority(): string
    {
        return '';
    }

    #[\Override]
    public function getUserInfo(): string
    {
        return '';
    }

    #[\Override]
    public function getHost(): string
    {
        return 'localhost';
    }

    #[\Override]
    public function getPort(): ?int
    {
        return null;
    }

    #[\Override]
    public function getPath(): string
    {
        return $this->path;
    }

    #[\Override]
    public function getQuery(): string
    {
        return $this->query;
    }

    #[\Override]
    public function getFragment(): string
    {
        return '';
    }

    #[\Override]
    public function withScheme(string $scheme): self
    {
        return clone $this;
    }

    #[\Override]
    public function withUserInfo(string $user, ?string $password = null): self
    {
        return clone $this;
    }

    #[\Override]
    public function withHost(string $host): self
    {
        return clone $this;
    }

    #[\Override]
    public function withPort(?int $port): self
    {
        return clone $this;
    }

    #[\Override]
    public function withPath(string $path): self
    {
        return clone $this;
    }

    #[\Override]
    public function withQuery(string $query): self
    {
        return clone $this;
    }

    #[\Override]
    public function withFragment(string $fragment): self
    {
        return clone $this;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->path;
    }
}
