<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Preset;

use Netresearch\NrLlm\Domain\DTO\ModelSelectionCriteria;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Preset\ConfigurationPreset;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigurationPreset::class)]
final class ConfigurationPresetTest extends TestCase
{
    private static function chatCriteria(): ModelSelectionCriteria
    {
        return new ModelSelectionCriteria(capabilities: ['chat']);
    }

    #[Test]
    public function acceptsNamespacedIdentifier(): void
    {
        $preset = new ConfigurationPreset(
            identifier: 'nr_ai_search.chat',
            name: 'AI Search Chat',
            description: 'Answers site-search questions.',
            criteria: self::chatCriteria(),
        );

        self::assertSame('nr_ai_search.chat', $preset->identifier);
        self::assertNull($preset->temperature);
        self::assertSame([], $preset->allowedToolGroups);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidIdentifiers(): array
    {
        return [
            'empty' => [''],
            'uppercase' => ['NrAiSearch.Chat'],
            'space' => ['nr ai.chat'],
            'dash' => ['nr-ai.chat'],
            'leading dot' => ['.chat'],
            'trailing dot' => ['chat.'],
            'double dot' => ['nr..chat'],
            'non-ascii' => ['über.chat'],
        ];
    }

    #[Test]
    #[DataProvider('invalidIdentifiers')]
    public function rejectsInvalidIdentifier(string $identifier): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1789347001);

        new ConfigurationPreset(
            identifier: $identifier,
            name: 'Name',
            description: '',
            criteria: self::chatCriteria(),
        );
    }

    #[Test]
    public function rejectsIdentifierExceedingColumnLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1789347002);

        new ConfigurationPreset(
            identifier: str_repeat('a', 101),
            name: 'Name',
            description: '',
            criteria: self::chatCriteria(),
        );
    }

    #[Test]
    public function rejectsCriteriaWithoutCapability(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1789347003);

        new ConfigurationPreset(
            identifier: 'ext.chat',
            name: 'Name',
            description: '',
            criteria: new ModelSelectionCriteria(adapterTypes: ['openai']),
        );
    }

    #[Test]
    public function rejectsNameExceedingColumnLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1789347005);

        new ConfigurationPreset(
            identifier: 'ext.chat',
            name: str_repeat('n', 256),
            description: '',
            criteria: self::chatCriteria(),
        );
    }

    #[Test]
    public function acceptsMultibyteNameUpToTheCharacterLimit(): void
    {
        // varchar(255) on utf8 limits CHARACTERS, not bytes: 255 umlauts are
        // 510 bytes but must pass (mb_strlen, not strlen).
        $preset = new ConfigurationPreset(
            identifier: 'ext.chat',
            name: str_repeat('ä', 255),
            description: '',
            criteria: self::chatCriteria(),
        );

        self::assertSame(255, mb_strlen($preset->name));
    }

    #[Test]
    public function rejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1789347005);

        new ConfigurationPreset(
            identifier: 'ext.chat',
            name: '',
            description: '',
            criteria: self::chatCriteria(),
        );
    }

    #[Test]
    public function checksumIsStableForIdenticalDeclarations(): void
    {
        $a = new ConfigurationPreset(
            identifier: 'ext.chat',
            name: 'Chat',
            description: 'A chat preset.',
            criteria: new ModelSelectionCriteria(capabilities: ['chat', 'tools'], minContextLength: 8000),
            systemPrompt: 'You are helpful.',
            temperature: 0.2,
            maxTokens: 2000,
            maxRequestsPerDay: 100,
            maxTokensPerDay: 50000,
            maxCostPerDay: 5.0,
            allowedToolGroups: ['rag'],
        );
        $b = new ConfigurationPreset(
            identifier: 'ext.chat',
            name: 'Chat',
            description: 'A chat preset.',
            criteria: new ModelSelectionCriteria(capabilities: ['chat', 'tools'], minContextLength: 8000),
            systemPrompt: 'You are helpful.',
            temperature: 0.2,
            maxTokens: 2000,
            maxRequestsPerDay: 100,
            maxTokensPerDay: 50000,
            maxCostPerDay: 5.0,
            allowedToolGroups: ['rag'],
        );

        self::assertSame($a->checksum(), $b->checksum());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a->checksum());
    }

    #[Test]
    public function checksumChangesWhenAnyDeclaredFieldChanges(): void
    {
        $base = new ConfigurationPreset(
            identifier: 'ext.chat',
            name: 'Chat',
            description: 'A chat preset.',
            criteria: self::chatCriteria(),
        );
        $changedDescription = new ConfigurationPreset(
            identifier: 'ext.chat',
            name: 'Chat',
            description: 'A different description.',
            criteria: self::chatCriteria(),
        );
        $changedCriteria = new ConfigurationPreset(
            identifier: 'ext.chat',
            name: 'Chat',
            description: 'A chat preset.',
            criteria: new ModelSelectionCriteria(capabilities: ['chat', 'vision']),
        );
        $changedOptional = new ConfigurationPreset(
            identifier: 'ext.chat',
            name: 'Chat',
            description: 'A chat preset.',
            criteria: self::chatCriteria(),
            temperature: 0.5,
        );

        self::assertNotSame($base->checksum(), $changedDescription->checksum());
        self::assertNotSame($base->checksum(), $changedCriteria->checksum());
        self::assertNotSame($base->checksum(), $changedOptional->checksum());
    }
}
