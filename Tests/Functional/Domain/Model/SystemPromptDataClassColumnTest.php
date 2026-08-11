<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Domain\Model;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

/**
 * The `system_prompt_data_class` column round-trips (ADR-155).
 *
 * A column, a property and an accessor can each be right while the three do
 * not meet: the property maps to a field name Extbase derives, and nothing in
 * a unit test would notice a schema that never gained the column. This asserts
 * the seam, not the gate — the gate's own behaviour is a unit test.
 */
#[CoversClass(LlmConfiguration::class)]
final class SystemPromptDataClassColumnTest extends AbstractFunctionalTestCase
{
    private LlmConfigurationRepository $subject;

    private PersistenceManager $persistenceManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject            = $this->getService(LlmConfigurationRepository::class);
        $this->persistenceManager = $this->getService(PersistenceManager::class);
    }

    #[Test]
    public function aDeclaredClassSurvivesTheDatabase(): void
    {
        $this->persist('classified-prompt', 'Our margin floor is 12%.', ToolDataClass::SECRET_ADJACENT->value);

        $retrieved = $this->subject->findOneByIdentifier('classified-prompt');

        self::assertInstanceOf(LlmConfiguration::class, $retrieved);
        self::assertSame(ToolDataClass::SECRET_ADJACENT->value, $retrieved->getSystemPromptDataClass());
        self::assertSame(ToolDataClass::SECRET_ADJACENT, $retrieved->getSystemPromptDataClassEnum());
    }

    #[Test]
    public function aRowThatNeverDeclaredOneReadsAsUndeclared(): void
    {
        // The migration shape: the column ships empty, and an empty column is
        // the absence of a statement rather than a class.
        $this->persist('unclassified-prompt', 'You are a helpful assistant.', '');

        $retrieved = $this->subject->findOneByIdentifier('unclassified-prompt');

        self::assertInstanceOf(LlmConfiguration::class, $retrieved);
        self::assertSame('', $retrieved->getSystemPromptDataClass());
        self::assertNull($retrieved->getSystemPromptDataClassEnum());
    }

    private function persist(string $identifier, string $systemPrompt, string $dataClass): void
    {
        $configuration = new LlmConfiguration();
        $configuration->setPid(0);
        $configuration->setIdentifier($identifier);
        $configuration->setName($identifier);
        $configuration->setSystemPrompt($systemPrompt);
        $configuration->setSystemPromptDataClass($dataClass);

        $this->subject->add($configuration);
        $this->persistenceManager->persistAll();

        // Force a real read rather than the identity map's copy of the object
        // just written — the point is that the column came back.
        $this->persistenceManager->clearState();
    }
}
