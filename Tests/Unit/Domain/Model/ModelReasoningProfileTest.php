<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Model;

use DateTimeImmutable;
use Netresearch\NrLlm\Domain\Enum\CapabilitySource;
use Netresearch\NrLlm\Domain\Enum\ReasoningEffort;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * The reasoning profile fields on the model entity (ADR-275).
 */
#[CoversNothing] // Domain/Model excluded from coverage in phpunit.xml
final class ModelReasoningProfileTest extends AbstractUnitTestCase
{
    #[Test]
    public function aNewModelHasNoOverrideAndNoDiscoveredProfile(): void
    {
        $model = new Model();

        self::assertSame('', $model->getReasoningOverride());
        self::assertNull($model->getReasoningOverrideFlag());
        self::assertSame('', $model->getReasoningEffortsOverride());
        self::assertSame([], $model->getReasoningEffortsOverrideList());
        self::assertSame('', $model->getToolRouteOverride());
        self::assertNull($model->getToolsOnChatCompletionsOverride());
        self::assertSame('', $model->getReasoningDiscovered());
        self::assertNull($model->getReasoningDiscoveredFlag());
        self::assertSame([], $model->getReasoningEffortsDiscoveredList());
        self::assertSame('', $model->getReasoningSource());
        self::assertNull($model->getReasoningSourceEnum());
        self::assertNull($model->getReasoningConfirmedDate());
    }

    #[Test]
    public function theReasoningOverrideTakesYesOrNoAndNothingElse(): void
    {
        $model = new Model();

        $model->setReasoningOverride('yes');
        self::assertTrue($model->getReasoningOverrideFlag());

        $model->setReasoningOverride('no');
        self::assertFalse($model->getReasoningOverrideFlag());

        $model->setReasoningOverride('maybe');
        self::assertSame('', $model->getReasoningOverride());
        self::assertNull($model->getReasoningOverrideFlag());
    }

    #[Test]
    public function theEffortOverrideIsStoredLowestFirstWithoutUnknownsOrDuplicates(): void
    {
        $model = new Model();

        $model->setReasoningEffortsOverride('xhigh, low,bogus,LOW,medium');

        self::assertSame('low,medium,xhigh', $model->getReasoningEffortsOverride());
        self::assertSame(
            [ReasoningEffort::Low, ReasoningEffort::Medium, ReasoningEffort::XHigh],
            $model->getReasoningEffortsOverrideList(),
        );
    }

    #[Test]
    public function theToolRouteOverrideNamesOneOfTheTwoEndpoints(): void
    {
        $model = new Model();

        $model->setToolRouteOverride('chat_completions');
        self::assertTrue($model->getToolsOnChatCompletionsOverride());

        $model->setToolRouteOverride('responses');
        self::assertFalse($model->getToolsOnChatCompletionsOverride());

        $model->setToolRouteOverride('websocket');
        self::assertSame('', $model->getToolRouteOverride());
        self::assertNull($model->getToolsOnChatCompletionsOverride());
    }

    #[Test]
    public function aProviderAnswerIsRecordedWithItsScaleSourceAndDate(): void
    {
        $when  = new DateTimeImmutable('2026-11-09 10:00:00');
        $model = new Model();

        $model->recordReasoningDiscovery(
            true,
            [ReasoningEffort::Max, ReasoningEffort::Low, ReasoningEffort::Medium],
            CapabilitySource::Discovery,
            $when,
        );

        self::assertSame('yes', $model->getReasoningDiscovered());
        self::assertTrue($model->getReasoningDiscoveredFlag());
        self::assertSame('low,medium,max', $model->getReasoningEffortsDiscovered());
        self::assertSame(
            [ReasoningEffort::Low, ReasoningEffort::Medium, ReasoningEffort::Max],
            $model->getReasoningEffortsDiscoveredList(),
        );
        self::assertSame(CapabilitySource::Discovery, $model->getReasoningSourceEnum());
        self::assertSame($when->getTimestamp(), $model->getReasoningConfirmedDate()?->getTimestamp());
    }

    #[Test]
    public function aMeasuredNoIsStoredAsNoNotAsEmpty(): void
    {
        $model = new Model();

        $model->recordReasoningDiscovery(false, [], CapabilitySource::Discovery, new DateTimeImmutable('2026-11-09'));

        self::assertSame('no', $model->getReasoningDiscovered());
        self::assertFalse($model->getReasoningDiscoveredFlag());
    }

    #[Test]
    public function aThirdPartyCatalogueAnswerCarriesNoScale(): void
    {
        $model = new Model();

        $model->recordReasoningDiscovery(
            true,
            [ReasoningEffort::Low, ReasoningEffort::Max],
            CapabilitySource::ThirdPartyCatalog,
            new DateTimeImmutable('2026-11-09'),
        );

        self::assertTrue($model->getReasoningDiscoveredFlag());
        self::assertSame('', $model->getReasoningEffortsDiscovered());
        self::assertSame([], $model->getReasoningEffortsDiscoveredList());
        self::assertSame(CapabilitySource::ThirdPartyCatalog, $model->getReasoningSourceEnum());
    }

    /**
     * A scale stored next to any source but the provider's own list is not
     * handed out, whatever wrote it.
     */
    #[Test]
    public function aScaleBesideAThirdPartySourceIsNotRead(): void
    {
        $model = new Model();
        $model->_setProperty('reasoningEffortsDiscovered', 'low,max');
        $model->_setProperty('reasoningSource', CapabilitySource::ThirdPartyCatalog->value);

        self::assertSame([], $model->getReasoningEffortsDiscoveredList());
    }

    #[Test]
    public function recordingADiscoveryLeavesTheOperatorFieldsAlone(): void
    {
        $model = new Model();
        $model->setReasoningOverride('no');
        $model->setReasoningEffortsOverride('low');
        $model->setToolRouteOverride('chat_completions');

        $model->recordReasoningDiscovery(true, [ReasoningEffort::High], CapabilitySource::Discovery, new DateTimeImmutable('2026-11-09'));

        self::assertSame('no', $model->getReasoningOverride());
        self::assertSame('low', $model->getReasoningEffortsOverride());
        self::assertSame('chat_completions', $model->getToolRouteOverride());
    }

    #[Test]
    public function thirdPartyCatalogIsNotAVerification(): void
    {
        self::assertFalse(CapabilitySource::ThirdPartyCatalog->isVerification());
        self::assertSame(CapabilitySource::ThirdPartyCatalog, CapabilitySource::tryFromStored('third_party_catalog'));
    }
}
