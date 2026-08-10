<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Routing;

use Netresearch\NrLlm\Domain\Enum\RoutingRejectionReason;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Service\Routing\EligibilityEvaluator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EligibilityEvaluator::class)]
final class EligibilityEvaluatorTest extends TestCase
{
    private EligibilityEvaluator $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new EligibilityEvaluator();
    }

    #[Test]
    public function aModelMeetingEveryConstraintIsEligible(): void
    {
        $reason = $this->subject->evaluate($this->model(), [
            'capabilities'     => ['chat'],
            'adapterTypes'     => ['openai'],
            'minContextLength' => 4000,
            'maxCostInput'     => 200,
        ]);

        self::assertNull($reason, 'null is the contract for "may serve"');
    }

    #[Test]
    public function emptyCriteriaAcceptEveryModel(): void
    {
        self::assertNull($this->subject->evaluate($this->model(capabilities: ''), []));
    }

    #[Test]
    public function aRequestedCapabilityTheModelDoesNotDeclareRejectsIt(): void
    {
        self::assertSame(
            RoutingRejectionReason::CAPABILITY_MISSING,
            $this->subject->evaluate($this->model(capabilities: 'chat'), ['capabilities' => ['vision']]),
        );
    }

    #[Test]
    public function aModelDeclaringNothingDoesNotSatisfyAnExplicitCapabilityRequest(): void
    {
        // Strict, unlike the operation axis below: the operator asked for it.
        self::assertSame(
            RoutingRejectionReason::CAPABILITY_MISSING,
            $this->subject->evaluate($this->model(capabilities: ''), ['capabilities' => ['chat']]),
        );
    }

    #[Test]
    public function anUndeclaredModelStillServesAnOperation(): void
    {
        // ADR-138: an empty capability CSV means "undeclared", not "cannot".
        // Refusing it would break installations that never filled the optional
        // field, for a fact nobody ever stated.
        self::assertNull(
            $this->subject->evaluate($this->model(capabilities: ''), ['operationCapability' => 'vision']),
        );
    }

    #[Test]
    public function aDeclaredModelWithoutTheOperationCapabilityIsRejected(): void
    {
        self::assertSame(
            RoutingRejectionReason::OPERATION_CAPABILITY_MISSING,
            $this->subject->evaluate($this->model(capabilities: 'chat'), ['operationCapability' => 'vision']),
        );
    }

    #[Test]
    public function anExcludedAdapterTypeRejectsTheModel(): void
    {
        self::assertSame(
            RoutingRejectionReason::ADAPTER_NOT_ALLOWED,
            $this->subject->evaluate($this->model(adapterType: 'ollama'), ['adapterTypes' => ['openai']]),
        );
    }

    #[Test]
    public function aModelWithoutAProviderCannotSatisfyAnAdapterRestriction(): void
    {
        $model = $this->model();
        $model->setProvider(null);

        self::assertSame(
            RoutingRejectionReason::ADAPTER_NOT_ALLOWED,
            $this->subject->evaluate($model, ['adapterTypes' => ['openai']]),
        );
    }

    #[Test]
    public function aContextWindowBelowTheMinimumRejectsTheModel(): void
    {
        self::assertSame(
            RoutingRejectionReason::CONTEXT_TOO_SMALL,
            $this->subject->evaluate($this->model(contextLength: 2000), ['minContextLength' => 4000]),
        );
    }

    #[Test]
    public function anUnknownContextWindowDoesNotMeetAStatedMinimum(): void
    {
        // The requirement cannot be shown to be satisfied, so the model is
        // refused rather than gambled on.
        self::assertSame(
            RoutingRejectionReason::CONTEXT_TOO_SMALL,
            $this->subject->evaluate($this->model(contextLength: 0), ['minContextLength' => 1]),
        );
    }

    #[Test]
    public function anInputCostAboveTheCeilingRejectsTheModel(): void
    {
        self::assertSame(
            RoutingRejectionReason::COST_ABOVE_LIMIT,
            $this->subject->evaluate($this->model(costInput: 500), ['maxCostInput' => 100]),
        );
    }

    #[Test]
    public function anUnpricedModelPassesACostCeiling(): void
    {
        // Deliberately the opposite direction to an unknown context length: an
        // unpriced model is usually a local one, and refusing it for a ceiling
        // it may well satisfy would be the wrong call.
        self::assertNull($this->subject->evaluate($this->model(costInput: 0), ['maxCostInput' => 100]));
    }

    #[Test]
    public function theOperationCapabilityIsReportedOnlyWhenEveryOtherConstraintPassed(): void
    {
        // Load-bearing order, not cosmetics. A caller reads
        // OPERATION_CAPABILITY_MISSING as "would have served, but not this
        // operation" — and ModelSelectionService raises a misconfiguration
        // error on it. A model the criteria excluded anyway must therefore
        // report the criteria's reason, or that error would name a model the
        // operator never wanted.
        $reason = $this->subject->evaluate(
            $this->model(capabilities: 'chat', adapterType: 'ollama'),
            ['adapterTypes' => ['openai'], 'operationCapability' => 'vision'],
        );

        self::assertSame(RoutingRejectionReason::ADAPTER_NOT_ALLOWED, $reason);
    }

    private function model(
        string $capabilities = 'chat',
        string $adapterType = 'openai',
        int $contextLength = 8000,
        int $costInput = 100,
    ): Model {
        $provider = new Provider();
        $provider->setAdapterType($adapterType);

        $model = new Model();
        $model->setIdentifier('model-1');
        $model->setCapabilities($capabilities);
        $model->setContextLength($contextLength);
        $model->setCostInput($costInput);
        $model->setProvider($provider);

        return $model;
    }
}
