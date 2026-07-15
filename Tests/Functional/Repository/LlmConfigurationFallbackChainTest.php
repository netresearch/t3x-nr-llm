<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Repository;

use Netresearch\NrLlm\Domain\DTO\FallbackChain;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

/**
 * End-to-end persistence test for the fallback_chain column.
 *
 * Exercises the full round-trip: DTO -> JSON column -> Extbase hydration
 * -> DTO. Confirms the Extbase property mapping (snake_case column ->
 * camelCase property) works as expected for the new field.
 *
 * `Classes/Domain/Model/` is excluded from the coverage source set (Build/
 * phpunit.xml and Build/FunctionalTests.xml alike), so `#[CoversClass(
 * LlmConfiguration::class)]` raised "not a valid target for code coverage",
 * which failOnWarning=true escalates to a failure. The claim went unnoticed
 * because coverage never actually ran between 2026-04-24 and now. The
 * behaviour below is unchanged and still asserted; only the coverage claim is
 * dropped, exactly as the enum tests do.
 */
#[CoversNothing]
class LlmConfigurationFallbackChainTest extends AbstractFunctionalTestCase
{
    private LlmConfigurationRepository $subject;
    private PersistenceManager $persistenceManager;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var LlmConfigurationRepository $subject */
        $subject = $this->get(LlmConfigurationRepository::class);
        $this->subject = $subject;

        /** @var PersistenceManager $persistenceManager */
        $persistenceManager = $this->get(PersistenceManager::class);
        $this->persistenceManager = $persistenceManager;
    }

    #[Test]
    public function emptyChainPersistsAndHydratesAsEmpty(): void
    {
        $identifier = 'fb-empty-' . uniqid();

        $config = new LlmConfiguration();
        $config->setIdentifier($identifier);
        $config->setName('Fallback Empty Test');
        // Not setting chain at all

        $this->subject->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $loaded = $this->subject->findOneByIdentifier($identifier);
        self::assertInstanceOf(LlmConfiguration::class, $loaded);
        self::assertFalse($loaded->hasFallbackChain());
        self::assertTrue($loaded->getFallbackChainDTO()->isEmpty());
        self::assertSame([], $loaded->getFallbackChainDTO()->configurationIdentifiers);
    }

    #[Test]
    public function populatedChainRoundTripsThroughDatabase(): void
    {
        $identifier = 'fb-populated-' . uniqid();
        $chain = new FallbackChain(['secondary', 'tertiary']);

        $config = new LlmConfiguration();
        $config->setIdentifier($identifier);
        $config->setName('Fallback Populated Test');
        $config->setFallbackChainDTO($chain);

        $this->subject->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $loaded = $this->subject->findOneByIdentifier($identifier);
        self::assertInstanceOf(LlmConfiguration::class, $loaded);
        self::assertTrue($loaded->hasFallbackChain());
        self::assertSame(
            ['secondary', 'tertiary'],
            $loaded->getFallbackChainDTO()->configurationIdentifiers,
        );
    }

    #[Test]
    public function chainOrderIsPreservedThroughPersistence(): void
    {
        $identifier = 'fb-order-' . uniqid();
        $chain = new FallbackChain(['third', 'first', 'second']);

        $config = new LlmConfiguration();
        $config->setIdentifier($identifier);
        $config->setName('Fallback Order Test');
        $config->setFallbackChainDTO($chain);

        $this->subject->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $loaded = $this->subject->findOneByIdentifier($identifier);
        self::assertInstanceOf(LlmConfiguration::class, $loaded);
        self::assertSame(
            ['third', 'first', 'second'],
            $loaded->getFallbackChainDTO()->configurationIdentifiers,
        );
    }

    #[Test]
    public function replacingChainWithEmptyClearsStoredJson(): void
    {
        $identifier = 'fb-clear-' . uniqid();

        $config = new LlmConfiguration();
        $config->setIdentifier($identifier);
        $config->setName('Fallback Clear Test');
        $config->setFallbackChainDTO(new FallbackChain(['a', 'b']));

        $this->subject->add($config);
        $this->persistenceManager->persistAll();

        // Load, clear, save
        $loaded = $this->subject->findOneByIdentifier($identifier);
        self::assertInstanceOf(LlmConfiguration::class, $loaded);
        $loaded->setFallbackChainDTO(new FallbackChain());
        $this->subject->update($loaded);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $reloaded = $this->subject->findOneByIdentifier($identifier);
        self::assertInstanceOf(LlmConfiguration::class, $reloaded);
        self::assertFalse($reloaded->hasFallbackChain());
        self::assertSame('', $reloaded->getFallbackChain());
    }
}
