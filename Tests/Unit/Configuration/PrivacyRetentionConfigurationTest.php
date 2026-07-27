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
            self::assertStringContainsString(
                'privacy.retention.' . $category->configKey(),
                $docs,
                sprintf(
                    'DataRetention.rst must document "privacy.retention.%s" for PrivacyDataCategory::%s',
                    $category->configKey(),
                    $category->name,
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
