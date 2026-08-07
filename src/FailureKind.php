<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency;

/**
 * How a failure thrown by the handler relates to a retry under the same key.
 *
 * @api
 */
enum FailureKind
{
    /**
     * Deterministic outcome of the business rules — a retry produces the same
     * failure, so it may be rendered and cached like a successful response.
     */
    case Domain;

    /**
     * Transient or environmental — a retry may succeed, so the claim is
     * released and the handler runs again.
     */
    case Infrastructure;

    /**
     * A defect in the code. The claim is released, nothing is cached, and the
     * failure surfaces so it can be fixed.
     */
    case Bug;
}
