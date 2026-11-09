<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Domain\Enum\ReasoningEffort;
use Netresearch\NrLlm\Provider\OpenAi\OpenAiModelProfile;
use Netresearch\NrLlm\Provider\OpenAi\OpenAiModelProfiles;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * What a `think` switch and a requested effort become for each OpenAI model
 * family (ADR-204).
 *
 * The profiles state what the model pages state, read on 2026-09-23. A case
 * here that goes red after a page changes means the table is stale, not the
 * test.
 */
#[CoversClass(OpenAiModelProfile::class)]
#[CoversClass(OpenAiModelProfiles::class)]
final class OpenAiReasoningEffortTest extends AbstractUnitTestCase
{
    #[Test]
    public function thinkingOffSelectsTheNoneEffortOnLuna(): void
    {
        $profile = OpenAiModelProfiles::forModel('gpt-6-luna');

        self::assertSame(ReasoningEffort::None, $profile->clamp(ReasoningEffort::None));
        self::assertSame(ReasoningEffort::None, $profile->lowestEffort());
    }

    #[Test]
    public function thinkingOffIsClampedToLowOnAstraAndSaysSo(): void
    {
        // Astra rejects `none` with HTTP 400. Refusing the call would turn a
        // preference into an error for a working model; sending `none` would
        // fail it. The clamp is the answer, and the provider records it.
        $profile = OpenAiModelProfiles::forModel('gpt-6-astra');

        self::assertFalse($profile->supports(ReasoningEffort::None));
        self::assertSame(ReasoningEffort::Low, $profile->lowestEffort());
        self::assertSame(ReasoningEffort::Low, $profile->clamp(ReasoningEffort::None));
    }

    #[Test]
    public function anEffortAboveTheScaleIsClampedDownward(): void
    {
        // A scale that stops at `high`: `max` must land there, not at the
        // floor. Hand-built, because no model in the table has such a scale.
        $profile = new OpenAiModelProfile(
            family: 'test',
            isReasoningModel: true,
            supportedEfforts: [ReasoningEffort::Low, ReasoningEffort::Medium, ReasoningEffort::High],
        );

        self::assertSame(ReasoningEffort::High, $profile->clamp(ReasoningEffort::Max));
    }

    #[Test]
    public function aTieBetweenTwoNeighboursGoesToTheHigherOne(): void
    {
        // `minimal` is not on the GPT-6 scale and sits between `none` and
        // `low`. A request for a little reasoning must not become none.
        $profile = OpenAiModelProfiles::forModel('gpt-6-luna');

        self::assertSame(ReasoningEffort::Low, $profile->clamp(ReasoningEffort::Minimal));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function modelsWithoutAVerifiedScale(): iterable
    {
        yield 'gpt-5.2' => ['gpt-5.2'];
        yield 'gpt-5.3-chat-latest' => ['gpt-5.3-chat-latest'];
        yield 'o1-mini (takes no reasoning_effort)' => ['o1-mini'];
        yield 'o3 (stops at high)' => ['o3'];
    }

    #[Test]
    #[DataProvider('modelsWithoutAVerifiedScale')]
    public function gpt5AndTheOSeriesHaveNoEffortScale(string $model): void
    {
        // Their scales differ inside each family and were not read from a
        // model page; an empty scale sends nothing, as before ADR-204.
        $profile = OpenAiModelProfiles::forModel($model);

        self::assertTrue($profile->isReasoningModel);
        self::assertFalse($profile->hasEffortScale());
        self::assertNull($profile->clamp(ReasoningEffort::None));
    }

    #[Test]
    public function aModelWithoutAScaleReceivesNoEffortAtAll(): void
    {
        // Null is not `None`: a model with no scale must get no `reasoning`
        // parameter whatsoever, a model whose floor is `None` must get one.
        $profile = OpenAiModelProfiles::forModel('gpt-4.1-mini');

        self::assertFalse($profile->hasEffortScale());
        self::assertNull($profile->clamp(ReasoningEffort::None));
        self::assertNull($profile->lowestEffort());
    }

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function modelFamilies(): iterable
    {
        // model id, is a reasoning model, serves tools on chat/completions
        yield 'gpt-6-astra' => ['gpt-6-astra', true, false];
        yield 'gpt-6-sol' => ['gpt-6-sol', true, false];
        yield 'gpt-6-luna' => ['gpt-6-luna', true, false];
        yield 'dated luna snapshot' => ['gpt-6-luna-2026-05-18', true, false];
        yield 'gpt-5.2' => ['gpt-5.2', true, true];
        yield 'o3' => ['o3', true, true];
        yield 'gpt-4.1-mini (the #965 control)' => ['gpt-4.1-mini', false, true];
        yield 'gpt-4o' => ['gpt-4o', false, true];
        yield 'a gateway deployment name' => ['my-azure-deployment', false, true];
    }

    #[Test]
    #[DataProvider('modelFamilies')]
    public function eachFamilyIsClassifiedAsItsModelPageSays(string $model, bool $reasoning, bool $toolsOnChat): void
    {
        $profile = OpenAiModelProfiles::forModel($model);

        self::assertSame($reasoning, $profile->isReasoningModel);
        self::assertSame($toolsOnChat, $profile->toolsOnChatCompletions);
    }

    #[Test]
    public function aDatedSnapshotBelongsToItsFamily(): void
    {
        self::assertSame('gpt-6-luna', OpenAiModelProfiles::forModel('gpt-6-luna-2026-05-18')->family);
    }

    #[Test]
    public function gpt61SolRejectsNoneAndClampsToItsLowMinimum(): void
    {
        foreach (['gpt-6.1-sol', 'gpt-6.1-sol-2026-11-01'] as $model) {
            $profile = OpenAiModelProfiles::forModel($model);
            self::assertSame('gpt-6.1-sol', $profile->family);
            self::assertTrue($profile->isReasoningModel);
            self::assertFalse($profile->supports(ReasoningEffort::None));
            self::assertSame(ReasoningEffort::Low, $profile->lowestEffort());
            self::assertSame(
                ReasoningEffort::Low,
                $profile->clamp(ReasoningEffort::None),
            );
            self::assertFalse($profile->toolsOnChatCompletions);
        }
    }

    /**
     * The scales OpenAI's HTTP 400 named as supported on 2026-11-09. `max` is
     * refused by all five, `minimal` too; `none` only by Astra and 6.1 Sol.
     *
     * @return iterable<string, array{string, list<ReasoningEffort>}>
     */
    public static function measuredScales(): iterable
    {
        $withNone    = [ReasoningEffort::None, ReasoningEffort::Low, ReasoningEffort::Medium, ReasoningEffort::High, ReasoningEffort::XHigh];
        $withoutNone = [ReasoningEffort::Low, ReasoningEffort::Medium, ReasoningEffort::High, ReasoningEffort::XHigh];

        yield 'gpt-6-sol' => ['gpt-6-sol', $withNone];
        yield 'gpt-6-luna' => ['gpt-6-luna', $withNone];
        yield 'gpt-6.1-terra' => ['gpt-6.1-terra', $withNone];
        yield 'gpt-6-astra' => ['gpt-6-astra', $withoutNone];
        yield 'gpt-6.1-sol' => ['gpt-6.1-sol', $withoutNone];
    }

    /**
     * @param list<ReasoningEffort> $scale
     */
    #[Test]
    #[DataProvider('measuredScales')]
    public function eachListedGpt6ModelSendsOnlyTheEffortsTheApiAccepts(string $model, array $scale): void
    {
        $profile = OpenAiModelProfiles::forModel($model);

        self::assertSame($scale, $profile->supportedEfforts);
        self::assertSame(ReasoningEffort::XHigh, $profile->clamp(ReasoningEffort::Max));
    }

    #[Test]
    public function gpt61TerraIsAListedFamilyWithItsSnapshots(): void
    {
        foreach (['gpt-6.1-terra', 'gpt-6.1-terra-2026-12-01'] as $model) {
            $profile = OpenAiModelProfiles::forModel($model);
            self::assertSame('gpt-6.1-terra', $profile->family);
            self::assertSame(ReasoningEffort::None, $profile->lowestEffort());
            self::assertFalse($profile->toolsOnChatCompletions);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unlistedGpt6Ids(): iterable
    {
        yield 'alias' => ['gpt-6-sol-latest'];
        yield 'pro variant' => ['gpt-6-astra-pro'];
        yield 'future member' => ['gpt-6.2-nova'];
    }

    /**
     * A GPT-6 id the table does not list keeps what every measured GPT-6
     * model shares — no sampling parameters, tools through Responses — and
     * gets no effort scale, so no effort is sent and none can be refused.
     */
    #[Test]
    #[DataProvider('unlistedGpt6Ids')]
    public function anUnlistedGpt6IdGetsTheFamilyDefaultWithoutAScale(string $model): void
    {
        $profile = OpenAiModelProfiles::forModel($model);

        self::assertSame('gpt-6', $profile->family);
        self::assertTrue($profile->isReasoningModel);
        self::assertFalse($profile->toolsOnChatCompletions);
        self::assertFalse($profile->hasEffortScale());
        self::assertNull($profile->clamp(ReasoningEffort::None));
    }

    #[Test]
    public function anUnknownGatewayModelDoesNotInheritAnEffortScale(): void
    {
        $profile = OpenAiModelProfiles::forModel('unlisted-gateway-model');
        self::assertFalse($profile->isReasoningModel);
        self::assertFalse($profile->hasEffortScale());
        self::assertNull($profile->lowestEffort());
        self::assertNull($profile->clamp(ReasoningEffort::None));
        self::assertTrue($profile->toolsOnChatCompletions);
    }
}
