<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Privacy;

use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Service\Privacy\ContentRedactor;
use Netresearch\NrLlm\Service\Privacy\RunPrivacyLevelResolver;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(RunPrivacyLevelResolver::class)]
final class RunPrivacyLevelResolverTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function aProviderTightensTheGlobalLevelForARun(): void
    {
        $this->seed(providerLevel: 'metadata');

        self::assertSame(PrivacyLevel::METADATA, $this->resolver(PrivacyLevel::FULL)->forRun(1));
    }

    #[Test]
    public function aProviderCannotLoosenTheGlobalLevel(): void
    {
        $this->seed(providerLevel: 'redacted');

        self::assertSame(PrivacyLevel::METADATA, $this->resolver(PrivacyLevel::METADATA)->forRun(1));
    }

    #[Test]
    public function anEmptyValueFollowsTheGlobalLevel(): void
    {
        $this->seed(providerLevel: '');

        self::assertSame(PrivacyLevel::FULL, $this->resolver(PrivacyLevel::FULL)->forRun(1));
    }

    #[Test]
    public function aTaskTightensTheRunItQueued(): void
    {
        $this->seed(providerLevel: 'redacted', taskLevel: 'none', origin: 'task:summarise');

        self::assertSame(PrivacyLevel::NONE, $this->resolver(PrivacyLevel::FULL)->forRun(1));
    }

    #[Test]
    public function aRunNoTaskQueuedDoesNotReadOne(): void
    {
        $this->seed(providerLevel: 'redacted', taskLevel: 'none', origin: 'fill:summarise');

        self::assertSame(PrivacyLevel::REDACTED, $this->resolver(PrivacyLevel::FULL)->forRun(1));
    }

    #[Test]
    public function aTaskHiddenAfterItQueuedTheRunStillTightensIt(): void
    {
        $this->seed(providerLevel: '', taskLevel: 'none', origin: 'task:summarise');
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);
        $pool->getConnectionForTable('tx_nrllm_task')->update('tx_nrllm_task', ['hidden' => 1], ['uid' => 8]);

        self::assertSame(PrivacyLevel::NONE, $this->resolver(PrivacyLevel::FULL)->forRun(1));
    }

    #[Test]
    public function aRunThatIsGoneHasTheGlobalLevel(): void
    {
        self::assertSame(PrivacyLevel::REDACTED, $this->resolver(PrivacyLevel::REDACTED)->forRun(99));
    }

    #[Test]
    public function aProviderIsFoundByItsIdentifier(): void
    {
        $this->seed(providerLevel: 'none');

        self::assertSame(PrivacyLevel::NONE, $this->resolver(PrivacyLevel::FULL)->forProvider('local-llm'));
        self::assertSame(PrivacyLevel::FULL, $this->resolver(PrivacyLevel::FULL)->forProvider('another'));
    }

    private function resolver(PrivacyLevel $global): RunPrivacyLevelResolver
    {
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);

        return new RunPrivacyLevelResolver(new FixedPrivacyPolicy($global, redactor: new ContentRedactor()), $pool);
    }

    private function seed(string $providerLevel, string $taskLevel = '', string $origin = ''): void
    {
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);
        $pool->getConnectionForTable('tx_nrllm_provider')->insert('tx_nrllm_provider', ['uid' => 5, 'identifier' => 'local-llm', 'name' => 'L', 'adapter_type' => 'openai', 'privacy_level' => $providerLevel]);
        $pool->getConnectionForTable('tx_nrllm_model')->insert('tx_nrllm_model', ['uid' => 6, 'identifier' => 'm', 'name' => 'M', 'model_id' => 'm', 'provider_uid' => 5]);
        $pool->getConnectionForTable('tx_nrllm_configuration')->insert('tx_nrllm_configuration', ['uid' => 7, 'identifier' => 'c', 'name' => 'C', 'model_uid' => 6]);
        $pool->getConnectionForTable('tx_nrllm_task')->insert('tx_nrllm_task', ['uid' => 8, 'identifier' => 'summarise', 'name' => 'T', 'privacy_level' => $taskLevel]);
        $pool->getConnectionForTable('tx_nrllm_agentrun')->insert('tx_nrllm_agentrun', ['uid' => 1, 'uuid' => 'u', 'configuration_uid' => 7, 'origin' => $origin]);
    }
}
