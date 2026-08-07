<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Idempotency\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Idempotency\DefaultFailureClassifier;
use Rasuvaeff\Yii3Idempotency\FailureClassifier;
use Rasuvaeff\Yii3Idempotency\FailureKind;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(DefaultFailureClassifier::class)]
final class DefaultFailureClassifierTest
{
    private DefaultFailureClassifier $classifier;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->classifier = new DefaultFailureClassifier();
    }

    public function implementsClassifierInterface(): void
    {
        Assert::instanceOf($this->classifier, FailureClassifier::class);
    }

    public function classifiesPlainExceptionAsDomain(): void
    {
        Assert::same($this->classifier->classify(new FakeDomainException('declined')), FailureKind::Domain);
    }

    public function classifiesRetryableMarkerAsInfrastructure(): void
    {
        Assert::same(
            $this->classifier->classify(new FakeRetryableException('connection reset')),
            FailureKind::Infrastructure,
        );
    }

    public function classifiesErrorAsInfrastructure(): void
    {
        Assert::same($this->classifier->classify(new \Error('boom')), FailureKind::Infrastructure);
    }

    public function classifiesErrorSubclassAsInfrastructure(): void
    {
        Assert::same($this->classifier->classify(new \TypeError('bad type')), FailureKind::Infrastructure);
    }

    public function overrideWinsOverExceptionDefault(): void
    {
        $classifier = new DefaultFailureClassifier([FakeDomainException::class => FailureKind::Bug]);

        Assert::same($classifier->classify(new FakeDomainException('oops')), FailureKind::Bug);
    }

    public function overrideWinsOverRetryableMarker(): void
    {
        $classifier = new DefaultFailureClassifier([FakeRetryableException::class => FailureKind::Domain]);

        Assert::same($classifier->classify(new FakeRetryableException('nope')), FailureKind::Domain);
    }

    public function overrideMatchesByInstanceOf(): void
    {
        $classifier = new DefaultFailureClassifier([\RuntimeException::class => FailureKind::Bug]);

        Assert::same($classifier->classify(new FakeDomainException('oops')), FailureKind::Bug);
    }

    public function overrideDeclarationOrderDecidesTheWinner(): void
    {
        $classifier = new DefaultFailureClassifier([
            FakeDomainException::class => FailureKind::Domain,
            \RuntimeException::class => FailureKind::Bug,
        ]);

        Assert::same($classifier->classify(new FakeDomainException('oops')), FailureKind::Domain);
    }

    public function overrideThatDoesNotMatchFallsBackToDefaults(): void
    {
        $classifier = new DefaultFailureClassifier([\LogicException::class => FailureKind::Bug]);

        Assert::same($classifier->classify(new FakeDomainException('oops')), FailureKind::Domain);
    }

    #[Property(runs: 200)]
    public function retryableMarkerIsNeverADomainFailure(string $message): void
    {
        Assert::same(
            (new DefaultFailureClassifier())->classify(new FakeRetryableException($message)),
            FailureKind::Infrastructure,
        );
    }

    /** @return array<string, ArbitraryInterface> */
    public static function retryableMarkerIsNeverADomainFailureGenerators(): array
    {
        return ['message' => Gen::stringAscii()];
    }
}
