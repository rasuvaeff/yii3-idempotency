<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(FakeRequest::class)]
final class FakeRequestTest
{
    public function anExplicitlyNullAttributeIsAValueNotAnAbsence(): void
    {
        // PSR-7: the default applies only when the attribute was never set. A
        // resolver reading `user` must be able to tell "no principal" from a
        // principal that happens to carry null.
        $request = new FakeRequest(attributes: ['user' => null]);

        Assert::null($request->getAttribute('user', 'fallback'));
        Assert::same($request->getAttribute('missing', 'fallback'), 'fallback');
    }
}
