<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

/**
 * Classifies a thrown failure without knowing anything about the application:
 *
 * 1. explicit overrides, in declaration order — the first matching class wins;
 * 2. {@see RetryableFailure} implementations — {@see FailureKind::Infrastructure};
 * 3. any other `\Exception` — {@see FailureKind::Domain};
 * 4. everything else (`\Error` and friends) — {@see FailureKind::Infrastructure}.
 *
 * {@see FailureKind::Bug} is never inferred: a defect is indistinguishable from
 * a domain outcome by type alone, so it has to be declared as an override.
 *
 * @api
 */
final readonly class DefaultFailureClassifier implements FailureClassifier
{
    /**
     * @param array<class-string, FailureKind> $overrides class name (matched with `instanceof`) => kind
     */
    public function __construct(
        private array $overrides = [],
    ) {}

    #[\Override]
    public function classify(\Throwable $failure): FailureKind
    {
        foreach ($this->overrides as $class => $kind) {
            if ($failure instanceof $class) {
                return $kind;
            }
        }

        if ($failure instanceof RetryableFailure) {
            return FailureKind::Infrastructure;
        }

        if ($failure instanceof \Exception) {
            return FailureKind::Domain;
        }

        return FailureKind::Infrastructure;
    }
}
