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
 *
 * It also holds the enum against the DISCOVERY side (#913), because a
 * capability nothing assigns is worse than a missing label: it renders
 * perfectly, an operator can require it in a configuration's criteria,
 * and then EligibilityEvaluator::matchesCapabilities() matches no model
 * at all. That is not hypothetical — nr_repurpose_text asked for
 * json_mode and its use-case pack could not be installed on any
 * installation until the criterion was dropped.
 */
#[CoversNothing]
final class ModelCapabilityRegistrationTest extends TestCase
{
    #[Test]
    public function tcaCapabilityItemsMatchEnumCases(): void
    {
        // require_once is safe here: no other unit-suite test includes this
        // TCA file, so the return value is always the configuration array.
        $tca = require_once __DIR__ . '/../../../Configuration/TCA/tx_nrllm_model.php';

        self::assertIsArray($tca);
        $columns = $tca['columns'] ?? null;
        self::assertIsArray($columns);
        $capabilitiesColumn = $columns['capabilities'] ?? null;
        self::assertIsArray($capabilitiesColumn);
        $config = $capabilitiesColumn['config'] ?? null;
        self::assertIsArray($config);
        $items = $config['items'] ?? null;
        self::assertIsArray($items);

        $itemValues = [];
        foreach ($items as $item) {
            self::assertIsArray($item);
            $value = $item['value'] ?? null;
            self::assertIsString($value);
            $itemValues[] = $value;
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
     * Capabilities the enum declares that NO model discoverer assigns.
     *
     * They are reachable only by an operator ticking the box on a model
     * record by hand, so a configuration requiring one matches nothing on a
     * default installation. Keeping the list here rather than deleting the
     * cases is deliberate: the TCA offers them, records may already carry
     * them, and whether the underlying models support the feature is a
     * question for the model catalogue, not for this test.
     *
     * Remove an entry when a discoverer starts assigning it — the test below
     * fails if a listed capability turns out to be assigned after all, so the
     * list cannot outlive its reason.
     *
     * @var list<string>
     */
    private const UNASSIGNED_BY_DISCOVERY = ['json_mode', 'audio'];

    /**
     * Whether any discoverer source mentions the capability as a string
     * literal.
     *
     * A text scan, because the discoverers have no common seam: only
     * OpenAiModelDiscoverer keeps a named spec table, the rest build their
     * DiscoveredModel capabilities inline. It is an APPROXIMATION: any
     * occurrence of the quoted value counts, including one in a comment or in
     * an unrelated string, so the answer can be a false positive.
     *
     * The two callers are affected in opposite directions, and neither is
     * "cannot invent a failure":
     *
     * - the coverage test reads a false positive as "assigned", so it goes
     *   quiet about a capability that is in fact dead — it under-reports;
     * - the staleness test reads the same false positive as "a discoverer now
     *   assigns this", so it FAILS on a capability nothing assigns — it can
     *   raise an alarm that is wrong.
     *
     * If the second one ever fires, check the hit before removing the entry:
     * `git grep -n "'<capability>'" -- Classes/Service/SetupWizard/Discovery`
     * shows whether it is an assignment or prose.
     */
    private function isAssignedByAnyDiscoverer(string $capability): bool
    {
        $needle = sprintf("'%s'", $capability);
        foreach (glob(__DIR__ . '/../../../Classes/Service/SetupWizard/Discovery/*.php') ?: [] as $file) {
            $source = file_get_contents($file);
            if (is_string($source) && str_contains($source, $needle)) {
                return true;
            }
        }

        return false;
    }

    #[Test]
    public function everyCapabilityIsAssignedBySomeDiscovererOrDeclaredUnassigned(): void
    {
        $unexpected = [];
        foreach (ModelCapability::values() as $value) {
            if (in_array($value, self::UNASSIGNED_BY_DISCOVERY, true)) {
                continue;
            }

            if (!$this->isAssignedByAnyDiscoverer($value)) {
                $unexpected[] = $value;
            }
        }

        self::assertSame(
            [],
            $unexpected,
            'No discoverer assigns these capabilities, so criteria requiring one match nothing: '
            . implode(', ', $unexpected)
            . '. Either assign them in the discovery catalog or add them to UNASSIGNED_BY_DISCOVERY with a reason.',
        );
    }

    #[Test]
    public function theUnassignedListDoesNotOutliveItsReason(): void
    {
        $nowAssigned = [];
        foreach (self::UNASSIGNED_BY_DISCOVERY as $value) {
            if ($this->isAssignedByAnyDiscoverer($value)) {
                $nowAssigned[] = $value;
            }
        }

        self::assertSame(
            [],
            $nowAssigned,
            'A discoverer now assigns these, so drop them from UNASSIGNED_BY_DISCOVERY: '
            . implode(', ', $nowAssigned),
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
}
