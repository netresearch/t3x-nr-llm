<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\ConfigurationCallPlanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The call options name the model the call runs on (ADR-247). A
 * criteria-mode configuration names none of its own, and an adapter that gets
 * no model falls back to its own default -- for an embedding a fixed name,
 * which on Azure is a deployment that may not exist.
 */
#[CoversClass(ConfigurationCallPlanner::class)]
final class ConfigurationCallPlannerModelOptionTest extends TestCase
{
    #[Test]
    public function aCriteriaModeCallCarriesTheResolvedModel(): void
    {
        $options = $this->planner()->callOptions(new LlmConfiguration(), $this->model('emb-deploy'), []);

        self::assertSame('emb-deploy', $options['model'] ?? null);
    }

    #[Test]
    public function aModelTheCallerNamedWins(): void
    {
        $options = $this->planner()->callOptions(new LlmConfiguration(), $this->model('emb-deploy'), ['model' => 'override']);

        self::assertSame('override', $options['model'] ?? null);
    }

    #[Test]
    public function aFixedModeConfigurationKeepsItsModel(): void
    {
        $configuration = new LlmConfiguration();
        $configuration->setLlmModel($this->model('fixed-model'));

        $options = $this->planner()->callOptions($configuration, $this->model('fixed-model'), []);

        self::assertSame('fixed-model', $options['model'] ?? null);
    }

    private function planner(): ConfigurationCallPlanner
    {
        return new ConfigurationCallPlanner(self::createStub(ProviderAdapterRegistryInterface::class));
    }

    private function model(string $id): Model
    {
        $model = new Model();
        $model->setModelId($id);

        return $model;
    }
}
