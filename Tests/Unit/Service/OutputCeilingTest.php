<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Service\OutputCeiling;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(OutputCeiling::class)]
final class OutputCeilingTest extends AbstractUnitTestCase
{
    #[Test]
    public function aReasoningModelOfOpenAiIsQuotedAtTheFloor(): void
    {
        self::assertSame(16000, OutputCeiling::of($this->configuration('openai', 'gpt-6-sol', 1000)));
    }

    #[Test]
    public function aLimitAboveTheFloorStays(): void
    {
        self::assertSame(32000, OutputCeiling::of($this->configuration('openai', 'gpt-6-sol', 32000)));
    }

    #[Test]
    public function aModelThatDoesNotThinkIsQuotedAtItsLimit(): void
    {
        self::assertSame(1000, OutputCeiling::of($this->configuration('openai', 'gpt-4o', 1000)));
    }

    #[Test]
    public function anAdapterWithoutTheFloorIsQuotedAtItsLimit(): void
    {
        self::assertSame(1000, OutputCeiling::of($this->configuration('anthropic', 'gpt-6-sol', 1000)));
    }

    #[Test]
    public function aConfigurationThatSwitchesThinkingOffIsQuotedAtItsLimit(): void
    {
        $configuration = $this->configuration('openai', 'gpt-6-sol', 1000);
        $configuration->setOptions('{"reasoning_effort":"none"}');

        self::assertSame(1000, OutputCeiling::of($configuration));
    }

    #[Test]
    public function aConfigurationWithThinkFalseIsQuotedAtItsLimit(): void
    {
        $configuration = $this->configuration('openai', 'gpt-6-sol', 1000);
        $configuration->setOptions('{"think":false}');

        self::assertSame(1000, OutputCeiling::of($configuration));
    }

    #[Test]
    public function aModelWithoutAnEffortScaleKeepsTheFloorWhateverTheOptionSays(): void
    {
        $configuration = $this->configuration('openai', 'gpt-5.2', 1000);
        $configuration->setOptions('{"reasoning_effort":"none"}');

        self::assertSame(16000, OutputCeiling::of($configuration));
    }

    #[Test]
    public function everyAdapterOfTheProviderGetsTheFloor(): void
    {
        self::assertSame(16000, OutputCeiling::of($this->configuration('together', 'gpt-6-sol', 1000)));
    }

    #[Test]
    public function noLimitIsNoCeiling(): void
    {
        self::assertNull(OutputCeiling::of($this->configuration('openai', 'gpt-6-sol', 0)));
    }

    private function configuration(string $adapter, string $modelId, int $maxTokens): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setAdapterType($adapter);
        $model = new Model();
        $model->setModelId($modelId);
        $model->setProvider($provider);
        $configuration = new LlmConfiguration();
        $configuration->setLlmModel($model);
        $configuration->setMaxTokens($maxTokens);

        return $configuration;
    }
}
