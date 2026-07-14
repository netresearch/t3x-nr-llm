<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Preset;

use Netresearch\NrLlm\Domain\DTO\ModelSelectionCriteria;
use Netresearch\NrLlm\Domain\Enum\ModelSelectionMode;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Service\Preset\ConfigurationPreset;
use Netresearch\NrLlm\Service\Preset\ConfigurationPresetDiffService;
use Netresearch\NrLlm\Service\Preset\PresetFieldDiff;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigurationPresetDiffService::class)]
final class ConfigurationPresetDiffServiceTest extends TestCase
{
    private ConfigurationPresetDiffService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new ConfigurationPresetDiffService();
    }

    /**
     * A record whose values match {@see baselinePreset()} field for field.
     */
    private static function baselineRecord(): LlmConfiguration
    {
        $record = new LlmConfiguration();
        $record->setIdentifier('ext.chat');
        $record->setName('Chat');
        $record->setDescription('A chat preset.');
        $record->setModelSelectionMode(ModelSelectionMode::CRITERIA->value);
        $record->setModelSelectionCriteriaDTO(new ModelSelectionCriteria(capabilities: ['chat']));
        $record->setSystemPrompt('You are helpful.');
        $record->setTemperature(0.2);
        $record->setMaxTokens(2000);
        $record->setAllowedToolGroups('rag,content');

        return $record;
    }

    private static function baselinePreset(): ConfigurationPreset
    {
        return new ConfigurationPreset(
            identifier: 'ext.chat',
            name: 'Chat',
            description: 'A chat preset.',
            criteria: new ModelSelectionCriteria(capabilities: ['chat']),
            systemPrompt: 'You are helpful.',
            temperature: 0.2,
            maxTokens: 2000,
            allowedToolGroups: ['rag', 'content'],
        );
    }

    /**
     * @param list<PresetFieldDiff> $changes
     */
    private static function change(array $changes, string $field): PresetFieldDiff
    {
        foreach ($changes as $change) {
            if ($change->field === $field) {
                return $change;
            }
        }
        self::fail(sprintf('No diff entry for field "%s".', $field));
    }

    #[Test]
    public function reportsNoChangeWhenDeclarationMatchesRecord(): void
    {
        $diff = $this->subject->diff(self::baselinePreset(), self::baselineRecord());

        self::assertFalse($diff->hasChanges());
        self::assertSame([], $diff->changedFields());
        self::assertSame('ext.chat', $diff->identifier);
    }

    #[Test]
    public function reportsNameAndDescriptionChanges(): void
    {
        $preset = new ConfigurationPreset(
            identifier: 'ext.chat',
            name: 'Chat v2',
            description: 'Updated.',
            criteria: new ModelSelectionCriteria(capabilities: ['chat']),
            systemPrompt: 'You are helpful.',
            temperature: 0.2,
            maxTokens: 2000,
            allowedToolGroups: ['rag', 'content'],
        );

        $changes = $this->subject->diff($preset, self::baselineRecord())->changes;

        self::assertSame('Chat', self::change($changes, 'name')->current);
        self::assertSame('Chat v2', self::change($changes, 'name')->declared);
        self::assertSame('Updated.', self::change($changes, 'description')->declared);
    }

    #[Test]
    public function reportsTemperatureChangeAtColumnScale(): void
    {
        $preset = new ConfigurationPreset(
            identifier: 'ext.chat',
            name: 'Chat',
            description: 'A chat preset.',
            criteria: new ModelSelectionCriteria(capabilities: ['chat']),
            systemPrompt: 'You are helpful.',
            temperature: 0.9,
            maxTokens: 2000,
            allowedToolGroups: ['rag', 'content'],
        );

        $change = self::change($this->subject->diff($preset, self::baselineRecord())->changes, 'temperature');

        self::assertSame('0.20', $change->current);
        self::assertSame('0.90', $change->declared);
    }

    #[Test]
    public function ignoresSubScaleTemperatureDifference(): void
    {
        // 0.201 rounds to the decimal(3,2) column value 0.20 the record holds.
        $preset = new ConfigurationPreset(
            identifier: 'ext.chat',
            name: 'Chat',
            description: 'A chat preset.',
            criteria: new ModelSelectionCriteria(capabilities: ['chat']),
            systemPrompt: 'You are helpful.',
            temperature: 0.201,
            maxTokens: 2000,
            allowedToolGroups: ['rag', 'content'],
        );

        $diff = $this->subject->diff($preset, self::baselineRecord());

        self::assertNotContains('temperature', $diff->changedFields());
    }

    #[Test]
    public function reportsCriteriaSubfieldChanges(): void
    {
        $preset = new ConfigurationPreset(
            identifier: 'ext.chat',
            name: 'Chat',
            description: 'A chat preset.',
            criteria: new ModelSelectionCriteria(
                capabilities: ['chat', 'vision'],
                minContextLength: 8000,
                preferLowestCost: true,
            ),
            systemPrompt: 'You are helpful.',
            temperature: 0.2,
            maxTokens: 2000,
            allowedToolGroups: ['rag', 'content'],
        );

        $changes = $this->subject->diff($preset, self::baselineRecord())->changes;

        self::assertSame('chat', self::change($changes, 'criteria.capabilities')->current);
        self::assertSame('chat, vision', self::change($changes, 'criteria.capabilities')->declared);
        self::assertSame('0', self::change($changes, 'criteria.minContextLength')->current);
        self::assertSame('8000', self::change($changes, 'criteria.minContextLength')->declared);
        self::assertSame('false', self::change($changes, 'criteria.preferLowestCost')->current);
        self::assertSame('true', self::change($changes, 'criteria.preferLowestCost')->declared);
    }

    #[Test]
    public function normalizesToolGroupsOrderBetweenCsvAndList(): void
    {
        // Declared list order differs from the record's CSV order, but the
        // sets are equal, so it is not a change.
        $record = self::baselineRecord();
        $record->setAllowedToolGroups('content,rag');

        $diff = $this->subject->diff(self::baselinePreset(), $record);

        self::assertNotContains('allowedToolGroups', $diff->changedFields());
    }

    #[Test]
    public function reportsToolGroupsSetChange(): void
    {
        $preset = new ConfigurationPreset(
            identifier: 'ext.chat',
            name: 'Chat',
            description: 'A chat preset.',
            criteria: new ModelSelectionCriteria(capabilities: ['chat']),
            systemPrompt: 'You are helpful.',
            temperature: 0.2,
            maxTokens: 2000,
            allowedToolGroups: ['rag'],
        );

        $diff = $this->subject->diff($preset, self::baselineRecord());

        self::assertContains('allowedToolGroups', $diff->changedFields());
    }

    #[Test]
    public function nullSeedIsNeverReportedEvenWhenRecordDiffers(): void
    {
        // The declaration carries no temperature seed: even though the record
        // holds 0.2, the update would not reset it, so it is not a change.
        $preset = new ConfigurationPreset(
            identifier: 'ext.chat',
            name: 'Chat',
            description: 'A chat preset.',
            criteria: new ModelSelectionCriteria(capabilities: ['chat']),
            systemPrompt: 'You are helpful.',
            temperature: null,
            maxTokens: 2000,
            allowedToolGroups: ['rag', 'content'],
        );

        $diff = $this->subject->diff($preset, self::baselineRecord());

        self::assertNotContains('temperature', $diff->changedFields());
    }

    #[Test]
    public function adminOwnedFieldsNeverAffectTheDiff(): void
    {
        // Flipping the record's activation / default flags produces no diff
        // entry: those fields are structurally absent from a preset.
        $record = self::baselineRecord();
        $record->setIsActive(true);
        $record->setIsDefault(true);

        $diff = $this->subject->diff(self::baselinePreset(), $record);

        self::assertFalse($diff->hasChanges());
    }
}
