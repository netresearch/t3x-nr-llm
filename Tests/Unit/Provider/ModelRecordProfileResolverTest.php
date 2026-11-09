<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use DateTimeImmutable;
use Netresearch\NrLlm\Domain\Enum\CapabilitySource;
use Netresearch\NrLlm\Domain\Enum\ReasoningEffort;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Provider\OpenAi\ModelRecordProfileResolver;
use Netresearch\NrLlm\Provider\OpenAi\OpenAiModelProfile;
use Netresearch\NrLlm\Provider\OpenAi\OpenAiModelProfiles;
use Netresearch\NrLlm\Provider\OpenAi\ProfileFieldSource;
use Netresearch\NrLlm\Provider\OpenAi\ResolvedModelProfile;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * The per-field resolution order of a model record's reasoning profile
 * (ADR-275): operator override, bundled table, discovered profile, family
 * default. The tool route skips the discovered profile.
 */
#[CoversClass(ModelRecordProfileResolver::class)]
#[CoversClass(ResolvedModelProfile::class)]
#[CoversClass(OpenAiModelProfiles::class)]
#[UsesClass(OpenAiModelProfile::class)]
final class ModelRecordProfileResolverTest extends AbstractUnitTestCase
{
    /**
     * R9: a record with no new field set sends exactly as the table alone did.
     */
    #[Test]
    #[DataProvider('modelIds')]
    public function aRecordWithoutProfileFieldsResolvesToTheTable(string $modelId): void
    {
        $resolved = ModelRecordProfileResolver::resolve($this->model($modelId));

        self::assertEquals(OpenAiModelProfiles::forModel($modelId), $resolved->profile);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function modelIds(): iterable
    {
        yield 'measured row' => ['gpt-6-astra'];
        yield 'dated snapshot' => ['gpt-6-luna-2026-05-18'];
        yield 'gpt-6 family default' => ['gpt-6.2-nova'];
        yield 'gpt-5 family' => ['gpt-5.2'];
        yield 'o-series' => ['o3'];
        yield 'unknown' => ['gpt-4.1-mini'];
    }

    #[Test]
    public function measuredAndFamilyDefaultSplitWhereTheTableEnds(): void
    {
        self::assertInstanceOf(OpenAiModelProfile::class, OpenAiModelProfiles::measured('gpt-6.1-terra'));
        self::assertInstanceOf(OpenAiModelProfile::class, OpenAiModelProfiles::measured('GPT-6-Astra-2026-05-18'));
        self::assertNull(OpenAiModelProfiles::measured('gpt-6.2-nova'));
        self::assertNull(OpenAiModelProfiles::measured('gpt-5.2'));
        self::assertTrue(OpenAiModelProfiles::familyDefault('gpt-6.2-nova')->isReasoningModel);
        self::assertSame('gpt-5', OpenAiModelProfiles::familyDefault('gpt-5.2')->family);
        self::assertFalse(OpenAiModelProfiles::familyDefault('acme-model')->isReasoningModel);
    }

    #[Test]
    public function theOperatorOverrideWinsOverTheTable(): void
    {
        $model = $this->model('gpt-6-astra');
        $model->setReasoningOverride('no');
        $model->setReasoningEffortsOverride('high');
        $model->setToolRouteOverride('chat_completions');

        $resolved = ModelRecordProfileResolver::resolve($model);

        self::assertFalse($resolved->profile->isReasoningModel);
        self::assertSame([ReasoningEffort::High], $resolved->profile->supportedEfforts);
        self::assertTrue($resolved->profile->toolsOnChatCompletions);
        self::assertSame(ProfileFieldSource::Operator, $resolved->reasoningSource);
        self::assertSame(ProfileFieldSource::Operator, $resolved->effortsSource);
        self::assertSame(ProfileFieldSource::Operator, $resolved->toolRouteSource);
    }

    #[Test]
    public function theOperatorOverrideWinsOverADiscoveredProfile(): void
    {
        $model = $this->model('acme-reasoner');
        $this->discovered($model, false, [ReasoningEffort::Low], CapabilitySource::Discovery);
        $model->setReasoningOverride('yes');
        $model->setReasoningEffortsOverride('medium,high');

        $resolved = ModelRecordProfileResolver::resolve($model);

        self::assertTrue($resolved->profile->isReasoningModel);
        self::assertSame([ReasoningEffort::Medium, ReasoningEffort::High], $resolved->profile->supportedEfforts);
    }

    #[Test]
    public function theTableWinsOverADiscoveredProfile(): void
    {
        $model = $this->model('gpt-6-astra');
        $this->discovered($model, false, [ReasoningEffort::Max], CapabilitySource::Discovery);

        $resolved = ModelRecordProfileResolver::resolve($model);

        self::assertTrue($resolved->profile->isReasoningModel);
        self::assertSame(OpenAiModelProfiles::forModel('gpt-6-astra')->supportedEfforts, $resolved->profile->supportedEfforts);
        self::assertSame(ProfileFieldSource::Table, $resolved->reasoningSource);
        self::assertSame(ProfileFieldSource::Table, $resolved->effortsSource);
    }

    #[Test]
    public function aDiscoveredProfileWinsOverTheFamilyDefault(): void
    {
        // gpt-6* without a row defaults to a reasoning model with no scale.
        $model = $this->model('gpt-6.2-nova');
        $this->discovered($model, false, [ReasoningEffort::Low, ReasoningEffort::High], CapabilitySource::Discovery);

        $resolved = ModelRecordProfileResolver::resolve($model);

        self::assertFalse($resolved->profile->isReasoningModel);
        self::assertSame([ReasoningEffort::Low, ReasoningEffort::High], $resolved->profile->supportedEfforts);
        self::assertSame(ProfileFieldSource::Discovery, $resolved->reasoningSource);
        self::assertSame(ProfileFieldSource::Discovery, $resolved->effortsSource);
    }

    #[Test]
    public function aThirdPartyCatalogueAnswerSetsReasoningButNoScale(): void
    {
        $model = $this->model('acme-reasoner');
        $this->discovered($model, true, [ReasoningEffort::Low], CapabilitySource::ThirdPartyCatalog);

        $resolved = ModelRecordProfileResolver::resolve($model);

        self::assertTrue($resolved->profile->isReasoningModel);
        self::assertSame([], $resolved->profile->supportedEfforts);
        self::assertSame(ProfileFieldSource::ThirdPartyCatalog, $resolved->reasoningSource);
        self::assertSame(ProfileFieldSource::FamilyDefault, $resolved->effortsSource);
        self::assertSame('third_party_catalog', $resolved->getReasoningSourceValue());
        self::assertTrue($resolved->isReasoningModel());
    }

    /**
     * No discovery source states the tool route for OpenAI's own endpoints;
     * a discovered "reasoning: yes" must not move the transport.
     */
    #[Test]
    public function theToolRouteIsNeverTakenFromADiscoveredProfile(): void
    {
        $model = $this->model('acme-reasoner');
        $this->discovered($model, true, [ReasoningEffort::Low], CapabilitySource::Discovery);

        $resolved = ModelRecordProfileResolver::resolve($model);

        self::assertTrue($resolved->profile->toolsOnChatCompletions);
        self::assertSame(ProfileFieldSource::FamilyDefault, $resolved->toolRouteSource);
    }

    #[Test]
    public function aDiscoveredValueWithoutADateIsNotADiscoveredProfile(): void
    {
        $model = $this->model('gpt-6.2-nova');
        $model->_setProperty('reasoningDiscovered', 'no');
        $model->_setProperty('reasoningSource', CapabilitySource::Discovery->value);

        $resolved = ModelRecordProfileResolver::resolve($model);

        self::assertTrue($resolved->profile->isReasoningModel);
        self::assertSame(ProfileFieldSource::FamilyDefault, $resolved->reasoningSource);
    }

    #[Test]
    public function theTableDefaultEffortIsKeptOnlyWhereTheResolvedScaleHasIt(): void
    {
        $model = $this->model('gpt-6-astra');
        self::assertSame(ReasoningEffort::Medium, ModelRecordProfileResolver::resolve($model)->profile->defaultEffort);

        $model->setReasoningEffortsOverride('high');
        self::assertNull(ModelRecordProfileResolver::resolve($model)->profile->defaultEffort);
    }

    #[Test]
    public function theProfileAppliesToTheOpenAiDialectAdaptersOnly(): void
    {
        self::assertTrue(ModelRecordProfileResolver::appliesTo($this->model('gpt-6-sol', 'openai')));
        // OpenRouter has its own adapter, which ignores this profile.
        self::assertFalse(ModelRecordProfileResolver::appliesTo($this->model('openai/gpt-6-sol', 'openrouter')));
        self::assertFalse(ModelRecordProfileResolver::appliesTo($this->model('openai/gpt-oss-120b', 'groq')));
        // OpenAI-compatible types are served by OpenAiProvider, which sends by it.
        foreach (['azure_openai', 'custom', 'together', 'fireworks', 'perplexity'] as $type) {
            self::assertTrue(ModelRecordProfileResolver::appliesTo($this->model('deepseek-r1', $type)), $type);
        }

        self::assertFalse(ModelRecordProfileResolver::appliesTo($this->model('claude-sonnet', 'anthropic')));
        self::assertFalse(ModelRecordProfileResolver::appliesTo(new Model()));
    }

    #[Test]
    public function theListMapHoldsOnlyPersistedModelsOfAConsumingAdapter(): void
    {
        $openAi = $this->model('gpt-6-sol');
        $openAi->_setProperty('uid', 7);

        $claude = $this->model('claude-sonnet', 'anthropic');
        $claude->_setProperty('uid', 8);

        $unsaved = $this->model('gpt-6-sol');

        $map = ModelRecordProfileResolver::resolveForList([$openAi, $claude, $unsaved, 'not a model']);

        self::assertSame([7], array_keys($map));
        self::assertTrue($map[7]->isReasoningModel());
    }

    private function model(string $modelId, string $adapterType = 'openai'): Model
    {
        $provider = new Provider();
        $provider->setAdapterType($adapterType);

        $model = new Model();
        $model->setModelId($modelId);
        $model->setProvider($provider);

        return $model;
    }

    /**
     * @param list<ReasoningEffort> $efforts
     */
    private function discovered(Model $model, bool $reasoning, array $efforts, CapabilitySource $source): void
    {
        $model->recordReasoningDiscovery($reasoning, $efforts, $source, new DateTimeImmutable('2026-11-09 10:00:00'));
    }
}
