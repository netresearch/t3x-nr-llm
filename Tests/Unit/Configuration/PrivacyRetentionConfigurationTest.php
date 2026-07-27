<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Configuration;

use Netresearch\NrLlm\Domain\Enum\PrivacyDataCategory;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * Guards the per-category retention overrides against drift.
 *
 * PrivacyPolicy::retentionDaysFor() resolves `privacy.retention.<configKey>`
 * from the extension configuration. A category whose key is missing from
 * ext_conf_template.txt still works — it silently falls back to the global
 * privacy.retentionDays — but the operator gets no field for it in the
 * Extension Configuration form and cannot see that the category exists. Adding
 * a case to the enum without adding the template line is therefore an invisible
 * regression, which is what this test exists to catch.
 */
#[CoversNothing]
final class PrivacyRetentionConfigurationTest extends AbstractUnitTestCase
{
    #[Test]
    public function everyPrivacyDataCategoryHasARetentionOverrideField(): void
    {
        $declared = $this->declaredRetentionKeys();

        foreach (PrivacyDataCategory::cases() as $category) {
            self::assertContains(
                $category->configKey(),
                $declared,
                sprintf(
                    'ext_conf_template.txt must declare "privacy.retention.%s" for PrivacyDataCategory::%s',
                    $category->configKey(),
                    $category->name,
                ),
            );
        }
    }

    #[Test]
    public function everyRetentionOverrideFieldMapsToAPrivacyDataCategory(): void
    {
        $known = array_map(
            static fn(PrivacyDataCategory $category): string => $category->configKey(),
            PrivacyDataCategory::cases(),
        );

        foreach ($this->declaredRetentionKeys() as $key) {
            self::assertContains(
                $key,
                $known,
                sprintf('ext_conf_template.txt declares "privacy.retention.%s", which no PrivacyDataCategory maps to', $key),
            );
        }
    }

    #[Test]
    public function everyPrivacyDataCategoryIsListedInTheRetentionDocumentation(): void
    {
        $docs = file_get_contents(dirname(__DIR__, 3) . '/Documentation/Administration/DataRetention.rst');
        self::assertIsString($docs, 'DataRetention.rst must be readable');

        foreach (PrivacyDataCategory::cases() as $category) {
            // Anchored on the RST literal's closing backticks: an unanchored
            // substring would let a future key that is a prefix of an existing
            // one (e.g. 'agent' vs the documented 'agentRun') pass without ever
            // being documented — the exact drift this guard exists to catch.
            self::assertStringContainsString(
                sprintf('``privacy.retention.%s``', $category->configKey()),
                $docs,
                sprintf(
                    'DataRetention.rst must document ``privacy.retention.%s`` for PrivacyDataCategory::%s',
                    $category->configKey(),
                    $category->name,
                ),
            );
        }
    }

    /**
     * TYPO3 splits an ext_conf_template comment on ';' before splitting the
     * label from its description, so a semicolon anywhere inside a label
     * truncates the operator-visible description at that point and silently
     * drops the rest — see AstConstantCommentVisitor::parseNodeComment().
     */
    #[Test]
    public function noSettingLabelContainsASemicolon(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/ext_conf_template.txt');
        self::assertIsString($contents, 'ext_conf_template.txt must be readable');

        foreach (explode("\n", $contents) as $number => $line) {
            $position = strpos($line, 'label=');
            if (!str_starts_with($line, '# cat=') || $position === false) {
                continue;
            }

            self::assertStringNotContainsString(
                ';',
                substr($line, $position),
                sprintf(
                    'ext_conf_template.txt line %d: a semicolon in a label truncates the description TYPO3 shows the operator',
                    $number + 1,
                ),
            );
        }
    }

    /**
     * @return list<string>
     */
    private function declaredRetentionKeys(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/ext_conf_template.txt');
        self::assertIsString($contents, 'ext_conf_template.txt must be readable');

        preg_match_all('/^privacy\.retention\.(\w+)\s*=/m', $contents, $matches);

        return $matches[1];
    }
}
