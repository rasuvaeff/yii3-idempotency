<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests\Support;

use Rasuvaeff\PropertyTesting\StateMachine\Command;

/**
 * Model-based command: claim the key at $index. Succeeds iff the key is neither
 * already claimed nor holding a stored record — the unique primary key of a
 * persistent adapter blocks both. The model is
 * `['claimed' => bool[], 'stored' => bool[]]`.
 */
final readonly class ClaimCommand implements Command
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
        \assert(is_array($model) && is_array($model['claimed']) && is_array($model['stored']));

        // The outcome is deterministic in the model: a claim that cannot be
        // granted (already claimed, or a stored record blocks the key) leaves
        // the claim flags untouched.
        if ($model['claimed'][$this->index] === false && $model['stored'][$this->index] === false) {
            $model['claimed'][$this->index] = true;
        }

        return $model;
    }

    #[\Override]
    public function run(mixed $model, mixed $system): mixed
    {
        \assert($system instanceof IdempotencyHarness && is_array($model) && is_array($model['stored']));

        return [
            'granted' => $system->claim($this->index),
            'loaded' => $system->loadedSnapshot(count($model['stored'])),
            'claimed' => $system->claimedSnapshot(count($model['stored'])),
        ];
    }

    #[\Override]
    public function postCondition(mixed $model, mixed $result): bool
    {
        \assert(is_array($model) && is_array($model['claimed']) && is_array($model['stored']) && is_array($result));

        $next = $this->nextState($model);
        $expectedGranted = $model['claimed'][$this->index] === false
            && $model['stored'][$this->index] === false;

        return $result['granted'] === $expectedGranted
            && $result['loaded'] === $next['stored']
            && $result['claimed'] === array_map(
                static fn(bool $claimed): ?string => $claimed ? 'fingerprint' : null,
                $next['claimed'],
            );
    }

    #[\Override]
    public function __toString(): string
    {
        return 'Claim(' . $this->index . ')';
    }
}
