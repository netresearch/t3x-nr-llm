<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Configuration;

use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Locks the ModelCapability enum, the tx_nrllm_model TCA capabilities
 * select, and the EN/DE translation catalogs together.
 *
 * A capability added to the enum without its TCA item is invisible in
 * the Models module; a TCA item without locallang entries renders raw
 * LLL keys; a missing locallang_be entry breaks the BE group permission
 * checkboxes that ext_localconf.php derives from the enum cases. This
 * test fails the build on any of those drifts.
 */
#[CoversNothing]
final class ModelCapabilityRegistrationTest extends TestCase
{
    #[Test]
    public function tcaCapabilityItemsMatchEnumCases(): void
    {
        $tca = require __DIR__ . '/../../../Configuration/TCA/tx_nrllm_model.php';

        self::assertIsArray($tca);
        assert(isset($tca['columns']) && is_array($tca['columns']));
        assert(isset($tca['columns']['capabilities']) && is_array($tca['columns']['capabilities']));
        assert(isset($tca['columns']['capabilities']['config']) && is_array($tca['columns']['capabilities']['config']));
        $items = $tca['columns']['capabilities']['config']['items'] ?? null;
        self::assertIsArray($items);

        $itemValues = [];
        foreach ($items as $item) {
            self::assertIsArray($item);
            assert(isset($item['value']) && is_string($item['value']));
            $itemValues[] = $item['value'];
        }

        $enumValues = ModelCapability::values();
        sort($itemValues);
        sort($enumValues);

        self::assertSame(
            $enumValues,
            $itemValues,
            'tx_nrllm_model.capabilities TCA items must mirror the ModelCapability enum cases',
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function languageFileProvider(): array
    {
        return [
            'EN TCA labels' => ['Resources/Private/Language/locallang_tca.xlf'],
            'DE TCA labels' => ['Resources/Private/Language/de.locallang_tca.xlf'],
        ];
    }

    #[Test]
    #[DataProvider('languageFileProvider')]
    public function everyCapabilityHasTcaLabelAndDescription(string $relativePath): void
    {
        $xlf = file_get_contents(__DIR__ . '/../../../' . $relativePath);
        self::assertIsString($xlf);

        foreach (ModelCapability::values() as $value) {
            self::assertStringContainsString(
                sprintf('id="tx_nrllm_model.capabilities.%s"', $value),
                $xlf,
                sprintf('Missing capability label "%s" in %s', $value, $relativePath),
            );
            self::assertStringContainsString(
                sprintf('id="tx_nrllm_model.capabilities.%s.description"', $value),
                $xlf,
                sprintf('Missing capability description "%s" in %s', $value, $relativePath),
            );
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function permissionLanguageFileProvider(): array
    {
        return [
            'EN BE permission labels' => ['Resources/Private/Language/locallang_be.xlf'],
            'DE BE permission labels' => ['Resources/Private/Language/de.locallang_be.xlf'],
        ];
    }

    #[Test]
    #[DataProvider('permissionLanguageFileProvider')]
    public function everyCapabilityHasPermissionLabel(string $relativePath): void
    {
        $xlf = file_get_contents(__DIR__ . '/../../../' . $relativePath);
        self::assertIsString($xlf);

        foreach (ModelCapability::values() as $value) {
            self::assertStringContainsString(
                sprintf('id="permissions.capability.%s"', $value),
                $xlf,
                sprintf('Missing BE permission label "%s" in %s', $value, $relativePath),
            );
        }
    }
}
