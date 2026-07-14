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
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\ModelSelectionServiceInterface;
use Netresearch\NrLlm\Service\Preset\ConfigurationPreset;
use Netresearch\NrLlm\Service\Preset\ConfigurationPresetDiffService;
use Netresearch\NrLlm\Service\Preset\ConfigurationPresetImportService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(ConfigurationPresetImportService::class)]
final class ConfigurationPresetImportServiceTest extends TestCase
{
    private ModelSelectionServiceInterface&MockObject $modelSelectionService;
    private LlmConfigurationRepository&MockObject $configurationRepository;
    private PersistenceManagerInterface&MockObject $persistenceManager;
    private ConfigurationPresetImportService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->modelSelectionService = $this->createMock(ModelSelectionServiceInterface::class);
        $this->configurationRepository = $this->createMock(LlmConfigurationRepository::class);
        $this->persistenceManager = $this->createMock(PersistenceManagerInterface::class);
        $this->subject = new ConfigurationPresetImportService(
            $this->modelSelectionService,
            $this->configurationRepository,
            $this->persistenceManager,
            new ConfigurationPresetDiffService(),
        );
    }

    private static function preset(): ConfigurationPreset
    {
        return new ConfigurationPreset(
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
            allowedToolGroups: ['rag', 'content'],
        );
    }

    #[Test]
    public function preflightReportsMatchedModelLabelWhenSatisfiable(): void
    {
        $model = new Model();
        $model->setName('Claude Sonnet');
        $this->modelSelectionService->method('findMatchingModel')->willReturn($model);

        $result = $this->subject->preflight(self::preset());

        self::assertTrue($result->satisfiable);
        self::assertSame('Claude Sonnet', $result->matchedModelLabel);
        self::assertNull($result->missingRequirement);
    }

    #[Test]
    public function preflightFallsBackToModelIdWhenModelHasNoName(): void
    {
        $model = new Model();
        $model->setModelId('claude-sonnet-4-5');
        $this->modelSelectionService->method('findMatchingModel')->willReturn($model);

        $result = $this->subject->preflight(self::preset());

        self::assertSame('claude-sonnet-4-5', $result->matchedModelLabel);
    }

    #[Test]
    public function preflightNamesMissingCapabilitiesWhenNoCandidateMatchesThem(): void
    {
        $this->modelSelectionService->method('findMatchingModel')->willReturn(null);
        $this->modelSelectionService->method('findCandidates')->willReturn([]);

        $result = $this->subject->preflight(self::preset());

        self::assertFalse($result->satisfiable);
        self::assertSame('capabilities: chat, tools', $result->missingRequirement);
        self::assertNull($result->matchedModelLabel);
    }

    #[Test]
    public function preflightNamesContextLengthWhenCapabilitiesAloneMatch(): void
    {
        $this->modelSelectionService->method('findMatchingModel')->willReturn(null);
        $this->modelSelectionService->method('findCandidates')->willReturnCallback(
            static fn(array $criteria): array => isset($criteria['minContextLength']) ? [] : [new Model()],
        );

        $result = $this->subject->preflight(self::preset());

        self::assertFalse($result->satisfiable);
        self::assertSame('minimum context length: 8000', $result->missingRequirement);
    }

    #[Test]
    public function importCreatesActiveCriteriaModeRecordWithChecksum(): void
    {
        $preset = self::preset();
        $model = new Model();
        $model->setName('Claude Sonnet');
        $this->modelSelectionService->method('findMatchingModel')->willReturn($model);
        $this->configurationRepository->method('findOneByIdentifier')->willReturn(null);

        $added = null;
        $this->configurationRepository->expects(self::once())->method('add')->willReturnCallback(
            static function (object $configuration) use (&$added): void {
                $added = $configuration;
            },
        );
        $this->persistenceManager->expects(self::once())->method('persistAll');

        $configuration = $this->subject->import($preset);

        self::assertSame($added, $configuration);
        self::assertSame('ext.chat', $configuration->getIdentifier());
        self::assertSame('Chat', $configuration->getName());
        self::assertSame('A chat preset.', $configuration->getDescription());
        self::assertSame(ModelSelectionMode::CRITERIA->value, $configuration->getModelSelectionMode());
        self::assertTrue($configuration->usesCriteriaSelection());
        self::assertSame($preset->criteria->toArray(), $configuration->getModelSelectionCriteriaDTO()->toArray());
        self::assertSame('You are helpful.', $configuration->getSystemPrompt());
        self::assertSame(0.2, $configuration->getTemperature());
        self::assertSame(2000, $configuration->getMaxTokens());
        self::assertSame(100, $configuration->getMaxRequestsPerDay());
        self::assertSame(50000, $configuration->getMaxTokensPerDay());
        self::assertSame(5.0, $configuration->getMaxCostPerDay());
        self::assertSame('rag,content', $configuration->getAllowedToolGroups());
        self::assertTrue($configuration->getIsActive());
        self::assertSame($preset->checksum(), $configuration->getPresetChecksum());
    }

    #[Test]
    public function importKeepsColumnDefaultsForUndeclaredOptionalFields(): void
    {
        $preset = new ConfigurationPreset(
            identifier: 'ext.minimal',
            name: 'Minimal',
            description: '',
            criteria: new ModelSelectionCriteria(capabilities: ['chat']),
        );
        $this->modelSelectionService->method('findMatchingModel')->willReturn(new Model());
        $this->configurationRepository->method('findOneByIdentifier')->willReturn(null);

        $configuration = $this->subject->import($preset);

        $defaults = new LlmConfiguration();
        self::assertSame($defaults->getSystemPrompt(), $configuration->getSystemPrompt());
        self::assertSame($defaults->getTemperature(), $configuration->getTemperature());
        self::assertSame($defaults->getMaxTokens(), $configuration->getMaxTokens());
        self::assertSame($defaults->getMaxRequestsPerDay(), $configuration->getMaxRequestsPerDay());
        self::assertSame($defaults->getMaxTokensPerDay(), $configuration->getMaxTokensPerDay());
        self::assertSame($defaults->getMaxCostPerDay(), $configuration->getMaxCostPerDay());
        self::assertSame($defaults->getAllowedToolGroups(), $configuration->getAllowedToolGroups());
    }

    #[Test]
    public function importThrowsWhenIdentifierAlreadyExists(): void
    {
        $this->configurationRepository->method('findOneByIdentifier')->willReturn(new LlmConfiguration());
        $this->configurationRepository->expects(self::never())->method('add');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1789347005);

        $this->subject->import(self::preset());
    }

    #[Test]
    public function importThrowsWhenPresetIsUnsatisfiable(): void
    {
        $this->configurationRepository->method('findOneByIdentifier')->willReturn(null);
        $this->modelSelectionService->method('findMatchingModel')->willReturn(null);
        $this->modelSelectionService->method('findCandidates')->willReturn([]);
        $this->configurationRepository->expects(self::never())->method('add');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1789347006);

        $this->subject->import(self::preset());
    }

    /**
     * A changed declaration for the same identifier: name, description,
     * temperature and criteria all differ from {@see preset()}.
     */
    private static function changedPreset(): ConfigurationPreset
    {
        return new ConfigurationPreset(
            identifier: 'ext.chat',
            name: 'Chat v2',
            description: 'An updated chat preset.',
            criteria: new ModelSelectionCriteria(capabilities: ['chat', 'tools', 'vision'], minContextLength: 16000),
            systemPrompt: 'You are very helpful.',
            temperature: 0.9,
            maxTokens: 4000,
            maxRequestsPerDay: 100,
            maxTokensPerDay: 50000,
            maxCostPerDay: 5.0,
            allowedToolGroups: ['rag', 'content'],
        );
    }

    /**
     * A criteria-mode record as imported from $importedFrom: its stored
     * checksum is that declaration's, so passing a *different* declaration to
     * update() makes it drifted.
     */
    private static function recordImportedFrom(ConfigurationPreset $importedFrom): LlmConfiguration
    {
        $record = new LlmConfiguration();
        $record->setIdentifier($importedFrom->identifier);
        $record->setModelSelectionMode(ModelSelectionMode::CRITERIA->value);
        $record->setModelSelectionCriteriaDTO($importedFrom->criteria);
        $record->setName($importedFrom->name);
        $record->setDescription($importedFrom->description);
        if ($importedFrom->systemPrompt !== null) {
            $record->setSystemPrompt($importedFrom->systemPrompt);
        }
        if ($importedFrom->temperature !== null) {
            $record->setTemperature($importedFrom->temperature);
        }
        if ($importedFrom->maxTokens !== null) {
            $record->setMaxTokens($importedFrom->maxTokens);
        }
        if ($importedFrom->maxRequestsPerDay !== null) {
            $record->setMaxRequestsPerDay($importedFrom->maxRequestsPerDay);
        }
        if ($importedFrom->maxTokensPerDay !== null) {
            $record->setMaxTokensPerDay($importedFrom->maxTokensPerDay);
        }
        if ($importedFrom->maxCostPerDay !== null) {
            $record->setMaxCostPerDay($importedFrom->maxCostPerDay);
        }
        if ($importedFrom->allowedToolGroups !== []) {
            $record->setAllowedToolGroups(implode(',', $importedFrom->allowedToolGroups));
        }
        $record->setPresetChecksum($importedFrom->checksum());

        return $record;
    }

    #[Test]
    public function updateAppliesDeclaredFieldsAndRestampsChecksum(): void
    {
        $preset = self::changedPreset();
        $record = self::recordImportedFrom(self::preset());
        $this->modelSelectionService->method('findMatchingModel')->willReturn(new Model());
        $this->configurationRepository->expects(self::once())->method('update')->with($record);
        $this->persistenceManager->expects(self::once())->method('persistAll');

        $diff = $this->subject->update($preset, $record);

        self::assertSame('Chat v2', $record->getName());
        self::assertSame('An updated chat preset.', $record->getDescription());
        self::assertSame(0.9, $record->getTemperature());
        self::assertSame(4000, $record->getMaxTokens());
        self::assertSame($preset->criteria->toArray(), $record->getModelSelectionCriteriaDTO()->toArray());
        self::assertSame($preset->checksum(), $record->getPresetChecksum());
        self::assertContains('name', $diff->changedFields());
        self::assertContains('temperature', $diff->changedFields());
        self::assertContains('criteria.capabilities', $diff->changedFields());
    }

    #[Test]
    public function updateLeavesAdminOwnedFieldsUntouched(): void
    {
        $preset = self::changedPreset();
        $record = self::recordImportedFrom(self::preset());
        $record->setIsActive(true);
        $record->setIsDefault(true);
        $this->modelSelectionService->method('findMatchingModel')->willReturn(new Model());

        $this->subject->update($preset, $record);

        self::assertTrue($record->getIsActive());
        self::assertTrue($record->getIsDefault());
    }

    #[Test]
    public function updateDoesNotResetRecordValueForNullSeed(): void
    {
        // The changed declaration removed the temperature seed (null): the
        // record keeps its imported temperature, and temperature is not in the
        // diff — yet the checksum change still resolves the drift.
        $preset = new ConfigurationPreset(
            identifier: 'ext.chat',
            name: 'Chat',
            description: 'A chat preset.',
            criteria: new ModelSelectionCriteria(capabilities: ['chat', 'tools'], minContextLength: 8000),
            systemPrompt: 'You are helpful.',
            temperature: null,
            maxTokens: 2000,
        );
        $record = self::recordImportedFrom(self::preset());
        $this->modelSelectionService->method('findMatchingModel')->willReturn(new Model());

        $diff = $this->subject->update($preset, $record);

        self::assertSame(0.2, $record->getTemperature());
        self::assertNotContains('temperature', $diff->changedFields());
        self::assertSame($preset->checksum(), $record->getPresetChecksum());
    }

    #[Test]
    public function updateRefusesWhenRecordIsNotDrifted(): void
    {
        $preset = self::changedPreset();
        $record = self::recordImportedFrom($preset); // stored checksum equals current declaration
        $this->configurationRepository->expects(self::never())->method('update');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(ConfigurationPresetImportService::CODE_UPDATE_NOT_DRIFTED);

        $this->subject->update($preset, $record);
    }

    #[Test]
    public function updateRefusesWhenRecordCarriesNoStoredChecksum(): void
    {
        $preset = self::changedPreset();
        $record = self::recordImportedFrom(self::preset());
        $record->setPresetChecksum('');
        $this->configurationRepository->expects(self::never())->method('update');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(ConfigurationPresetImportService::CODE_UPDATE_NOT_DRIFTED);

        $this->subject->update($preset, $record);
    }

    #[Test]
    public function updateRefusesWhenModeSwitchedToFixed(): void
    {
        $preset = self::changedPreset();
        $record = self::recordImportedFrom(self::preset());
        $record->setModelSelectionMode(ModelSelectionMode::FIXED->value);
        $this->configurationRepository->expects(self::never())->method('update');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(ConfigurationPresetImportService::CODE_UPDATE_MODE_SWITCHED);

        $this->subject->update($preset, $record);
    }

    #[Test]
    public function updateRefusesWhenUpdatedCriteriaUnsatisfiable(): void
    {
        $preset = self::changedPreset();
        $record = self::recordImportedFrom(self::preset());
        $this->modelSelectionService->method('findMatchingModel')->willReturn(null);
        $this->modelSelectionService->method('findCandidates')->willReturn([]);
        $this->configurationRepository->expects(self::never())->method('update');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(ConfigurationPresetImportService::CODE_UPDATE_UNSATISFIABLE);

        $this->subject->update($preset, $record);
    }

    #[Test]
    public function updateRefusesWhenRecordBelongsToAnotherIdentifier(): void
    {
        $preset = self::changedPreset();
        $record = self::recordImportedFrom(self::preset());
        $record->setIdentifier('ext.other');
        $this->configurationRepository->expects(self::never())->method('update');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(ConfigurationPresetImportService::CODE_UPDATE_IDENTIFIER_MISMATCH);

        $this->subject->update($preset, $record);
    }

    #[Test]
    public function previewUpdateReturnsDiffWithoutPersisting(): void
    {
        $preset = self::changedPreset();
        $record = self::recordImportedFrom(self::preset());
        $this->modelSelectionService->method('findMatchingModel')->willReturn(new Model());
        $this->configurationRepository->expects(self::never())->method('update');
        $this->persistenceManager->expects(self::never())->method('persistAll');

        $diff = $this->subject->previewUpdate($preset, $record);

        self::assertTrue($diff->hasChanges());
        self::assertContains('description', $diff->changedFields());
    }
}
