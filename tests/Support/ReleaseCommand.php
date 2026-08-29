<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests\Support;

use Rasuvaeff\PropertyTesting\StateMachine\Command;

/**
 * Model-based command: release the claim on the key at $index. This clears the
 * claim so the key can be claimed again, but must NOT drop a stored record. The
 * model is `['claimed' => bool[], 'stored' => bool[]]`.
 */
final readonly class ReleaseCommand implements Command
{
    public function __construct(private int $index) {}

    #[\Override]
    public function preCondition(mixed $model): bool
    {
        return true;
    }

    #[\Override]
    public function nextState(mixed $model): mixed
    {
        \assert(is_array($model) && is_array($model['claimed']));

        $model['claimed'][$this->index] = false;

        return $model;
    }

    #[\Override]
    public function run(mixed $model, mixed $system): mixed
    {
        \assert($system instanceof IdempotencyHarness && is_array($model) && is_array($model['stored']));

        $system->release($this->index);

        return [
            'loaded' => $system->loadedSnapshot(count($model['stored'])),
            'claimed' => $system->claimedSnapshot(count($model['stored'])),
        ];
    }

    #[\Override]
    public function postCondition(mixed $model, mixed $result): bool
    {
        \assert(is_array($result));

        $next = $this->nextState($model);

        // release() clears the claim but leaves the stored record intact.
        return $result['loaded'] === $next['stored']
            && $result['claimed'] === array_map(
                static fn(bool $claimed): ?string => $claimed ? 'fingerprint' : null,
                $next['claimed'],
            );
    }

    #[\Override]
    public function __toString(): string
    {
        return 'Release(' . $this->index . ')';
    }
}
