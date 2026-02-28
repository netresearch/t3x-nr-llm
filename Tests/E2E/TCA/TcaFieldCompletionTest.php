<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\E2E\TCA;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * E2E test to ensure all TCA fields have complete label and description.
 *
 * This test enforces that every TCA field (except system fields like 'hidden')
 * has both a label and a description defined, ensuring good UX in the TYPO3 backend.
 */
final class TcaFieldCompletionTest extends TestCase
{
    /**
     * Fields that are exempt from the description requirement.
     * These are either system fields or self-explanatory fields.
     */
    private const EXEMPT_FIELDS = [
        'hidden',      // Core system field with standard label
        'deleted',     // Core system field (not shown in forms)
        'tstamp',      // Core system field (not shown in forms)
        'crdate',      // Core system field (not shown in forms)
        'sorting',     // Core system field (not shown in forms)
        'description', // Self-referential - a description field doesn't need a description
    ];

    /**
     * @return array<string, array{0: string}>
     */
    public static function tcaTableProvider(): array
    {
        return [
            'provider' => ['tx_nrllm_provider'],
            'model' => ['tx_nrllm_model'],
            'configuration' => ['tx_nrllm_configuration'],
            'task' => ['tx_nrllm_task'],
        ];
    }

    #[Test]
    #[DataProvider('tcaTableProvider')]
    public function allFieldsHaveLabelAndDescription(string $tableName): void
    {
        $tcaFile = __DIR__ . '/../../../Configuration/TCA/' . $tableName . '.php';

        self::assertFileExists($tcaFile, sprintf('TCA file for table %s not found', $tableName));

        $tca = require $tcaFile;

        self::assertIsArray($tca, sprintf('TCA for %s must return an array', $tableName));
        /** @var array<string, mixed> $tca */
        self::assertArrayHasKey('columns', $tca, sprintf('TCA for %s must have columns', $tableName));

        /** @var array<string, array<string, mixed>> $columns */
        $columns = $tca['columns'];
        $missingLabels = [];
        $missingDescriptions = [];

        foreach ($columns as $fieldName => $fieldConfig) {
            // Skip exempt fields
            if (\in_array($fieldName, self::EXEMPT_FIELDS, true)) {
                continue;
            }

            // Check for label
            if (!isset($fieldConfig['label']) || $fieldConfig['label'] === '') {
                $missingLabels[] = $fieldName;
            }

            // Check for description
            if (!isset($fieldConfig['description']) || $fieldConfig['description'] === '') {
                $missingDescriptions[] = $fieldName;
            }
        }

        // Assert no missing labels
        self::assertEmpty(
            $missingLabels,
            sprintf(
                'Table %s has fields without labels: %s',
                $tableName,
                implode(', ', $missingLabels),
            ),
        );

        // Assert no missing descriptions
        self::assertEmpty(
            $missingDescriptions,
            sprintf(
                'Table %s has fields without descriptions: %s',
                $tableName,
                implode(', ', $missingDescriptions),
            ),
        );
    }

    #[Test]
    #[DataProvider('tcaTableProvider')]
    public function allLabelsAndDescriptionsAreTranslatable(string $tableName): void
    {
        $tcaFile = __DIR__ . '/../../../Configuration/TCA/' . $tableName . '.php';

        /** @var array<string, mixed> $tca */
        $tca = require $tcaFile;

        /** @var array<string, array<string, mixed>> $columns */
        $columns = $tca['columns'];

        $nonTranslatableLabels = [];
        $nonTranslatableDescriptions = [];

        foreach ($columns as $fieldName => $fieldConfig) {
            if (\in_array($fieldName, self::EXEMPT_FIELDS, true)) {
                continue;
            }

            $label = $fieldConfig['label'] ?? '';
            $description = $fieldConfig['description'] ?? '';

            // Check label is LLL reference
            if (\is_string($label) && $label !== '' && !str_starts_with($label, 'LLL:')) {
                $nonTranslatableLabels[] = $fieldName;
            }

            // Check description is LLL reference (if set)
            if (\is_string($description) && $description !== '' && !str_starts_with($description, 'LLL:')) {
                $nonTranslatableDescriptions[] = $fieldName;
            }
        }

        self::assertEmpty(
            $nonTranslatableLabels,
            sprintf(
                'Table %s has non-translatable labels (must use LLL:): %s',
                $tableName,
                implode(', ', $nonTranslatableLabels),
            ),
        );

        self::assertEmpty(
            $nonTranslatableDescriptions,
            sprintf(
                'Table %s has non-translatable descriptions (must use LLL:): %s',
                $tableName,
                implode(', ', $nonTranslatableDescriptions),
            ),
        );
    }

    #[Test]
    #[DataProvider('tcaTableProvider')]
    public function allTranslationKeysExist(string $tableName): void
    {
        $tcaFile = __DIR__ . '/../../../Configuration/TCA/' . $tableName . '.php';

        /** @var array<string, mixed> $tca */
        $tca = require $tcaFile;

        /** @var array<string, array<string, mixed>> $columns */
        $columns = $tca['columns'];

        $locallangFile = __DIR__ . '/../../../Resources/Private/Language/locallang_tca.xlf';
        self::assertFileExists($locallangFile, 'locallang_tca.xlf not found');

        $xliffContent = file_get_contents($locallangFile);
        self::assertNotFalse($xliffContent);

        $missingTranslations = [];

        foreach ($columns as $fieldName => $fieldConfig) {
            if (\in_array($fieldName, self::EXEMPT_FIELDS, true)) {
                continue;
            }

            $label = $fieldConfig['label'] ?? '';
            $description = $fieldConfig['description'] ?? '';

            // Check label translation exists
            if (\is_string($label) && str_starts_with($label, 'LLL:EXT:nr_llm/')) {
                $transUnitId = $this->extractTransUnitId($label);
                if ($transUnitId !== null && !str_contains($xliffContent, 'id="' . $transUnitId . '"')) {
                    $missingTranslations[] = $transUnitId . ' (label for ' . $fieldName . ')';
                }
            }

            // Check description translation exists
            if (\is_string($description) && str_starts_with($description, 'LLL:EXT:nr_llm/')) {
                $transUnitId = $this->extractTransUnitId($description);
                if ($transUnitId !== null && !str_contains($xliffContent, 'id="' . $transUnitId . '"')) {
                    $missingTranslations[] = $transUnitId . ' (description for ' . $fieldName . ')';
                }
            }
        }

        self::assertEmpty(
            $missingTranslations,
            sprintf(
                'Table %s has missing translations in locallang_tca.xlf: %s',
                $tableName,
                implode(', ', $missingTranslations),
            ),
        );
    }

    /**
     * Extract trans-unit ID from LLL reference.
     */
    private function extractTransUnitId(string $lllReference): ?string
    {
        // LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_provider.name
        if (preg_match('/locallang_tca\.xlf:(.+)$/', $lllReference, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
