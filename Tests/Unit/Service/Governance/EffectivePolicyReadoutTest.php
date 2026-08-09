<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Governance;

use Netresearch\NrLlm\Domain\Enum\PrivacyDataCategory;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Service\Governance\EffectivePolicyReadout;
use Netresearch\NrLlm\Service\Governance\EffectivePolicyRow;
use Netresearch\NrLlm\Service\Privacy\ContentRedactor;
use Netresearch\NrLlm\Service\Privacy\PrivacyPolicy;
use Netresearch\NrLlm\Service\Privacy\PrivacyPolicyInterface;
use Netresearch\NrLlm\Service\Skill\SkillComposerFactory;
use Netresearch\NrLlm\Service\Tool\DataClassEnforcementResolver;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(EffectivePolicyReadout::class)]
#[CoversClass(EffectivePolicyRow::class)]
final class EffectivePolicyReadoutTest extends TestCase
{
    #[Test]
    public function theFourKeysAreReportedInOrderWithTheirRuntimeReader(): void
    {
        $rows = $this->readout(
            new FixedPrivacyPolicy(PrivacyLevel::REDACTED, 90),
            ['tools' => ['dataClassEnforcement' => 'observe'], 'skills' => ['minTrustLevel' => 'verified']],
        )->rows();

        self::assertSame(
            ['privacy.level', 'privacy.retentionDays', 'tools.dataClassEnforcement', 'skills.minTrustLevel'],
            array_map(static fn(EffectivePolicyRow $row): string => $row->key, $rows),
        );
        self::assertSame(
            ['redacted', '90', 'observe', 'verified'],
            array_map(static fn(EffectivePolicyRow $row): ?string => $row->value, $rows),
        );
        self::assertSame(
            [
                FixedPrivacyPolicy::class,
                FixedPrivacyPolicy::class,
                DataClassEnforcementResolver::class,
                SkillComposerFactory::class,
            ],
            array_map(static fn(EffectivePolicyRow $row): string => $row->reader, $rows),
        );

        foreach ($rows as $row) {
            self::assertTrue($row->isKnown());
        }
    }

    #[Test]
    public function theReaderIsTheRuntimeClassNotAHardcodedName(): void
    {
        // The production wiring reads privacy through PrivacyPolicy; the readout
        // reports whatever class DI handed it, so the column cannot go stale.
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([]);

        $rows = $this->readout(
            new PrivacyPolicy($extensionConfiguration, new ContentRedactor()),
            [],
        )->rows();

        self::assertSame(PrivacyPolicy::class, $rows[0]->reader);
    }

    #[Test]
    public function aResolverThatCannotBeAskedYieldsUnknownAndNeverAValue(): void
    {
        $unreadable = $this->createMock(PrivacyPolicyInterface::class);
        $unreadable->method('level')->willThrowException(new RuntimeException('privacy resolver unavailable', 1785200001));
        $unreadable->method('retentionDays')->willReturn(30);
        // No category override: the interface guarantees at least 1 day and
        // falls back to the global window, which an unstubbed mock (0) does not.
        $unreadable->method('retentionDaysFor')->willReturn(30);

        $rows = $this->readout($unreadable, ['tools' => ['dataClassEnforcement' => 'observe']])->rows();

        // The failing row is unknown — not "metadata", not the shipped default.
        self::assertFalse($rows[0]->isKnown());
        self::assertNull($rows[0]->value);
        // ... and only that row. A single unavailable resolver does not blank the page.
        self::assertTrue($rows[1]->isKnown());
        self::assertSame('30', $rows[1]->value);
        self::assertSame('observe', $rows[2]->value);
    }

    #[Test]
    public function theFailClosedResolversNeverReportUnknownBecauseTheyCannotThrow(): void
    {
        // A broken extension configuration is a resolver ANSWER (fail-closed to
        // enforce / untrusted), not an unanswerable one — ADR-113 / ADR-061.
        $throwing = $this->createMock(ExtensionConfiguration::class);
        $throwing->method('get')->willThrowException(new RuntimeException('config unreadable', 1785200002));

        $rows = (new EffectivePolicyReadout(
            new FixedPrivacyPolicy(),
            new DataClassEnforcementResolver($throwing),
            new SkillComposerFactory($throwing),
        ))->rows();

        self::assertSame('enforce', $rows[2]->value);
        self::assertSame('untrusted', $rows[3]->value);
    }

    #[Test]
    public function observeIsQualifiedBecauseItNeverAppliesToRemoteTools(): void
    {
        // The gate reads `enforcing() || $tool instanceof RemoteToolInterface`,
        // so the word "observe" alone is a true value and a false statement.
        $rows = $this->readout(
            new FixedPrivacyPolicy(),
            ['tools' => ['dataClassEnforcement' => 'observe']],
        )->rows();

        self::assertSame('observe', $rows[2]->value);
        self::assertSame(
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:governance.note.remoteAlwaysEnforced',
            $rows[2]->noteKey,
        );
    }

    #[Test]
    public function enforceCarriesNoQualificationBecauseItHasNoException(): void
    {
        $rows = $this->readout(
            new FixedPrivacyPolicy(),
            ['tools' => ['dataClassEnforcement' => 'enforce']],
        )->rows();

        self::assertSame('enforce', $rows[2]->value);
        self::assertNull($rows[2]->noteKey);
    }

    #[Test]
    public function theShippedRetentionOverridesAddNoRowsBecauseTheyResolveToTheGlobalWindow(): void
    {
        $rows = $this->readoutOnConfiguration([
            'privacy' => [
                'retentionDays' => 30,
                'retention'     => array_fill_keys(PrivacyDataCategory::values(), 0),
            ],
        ])->rows();

        self::assertSame(
            ['privacy.level', 'privacy.retentionDays', 'tools.dataClassEnforcement', 'skills.minTrustLevel'],
            array_map(static fn(EffectivePolicyRow $row): string => $row->key, $rows),
        );
    }

    #[Test]
    public function aRetentionOverrideThatMovesAWindowGetsItsOwnRow(): void
    {
        // Without the row the page shows "30" as the only retention number
        // while the purge command deletes transcripts after 7 days.
        $rows = $this->readoutOnConfiguration([
            'privacy' => [
                'retentionDays' => 30,
                'retention'     => ['conversation' => 7, 'telemetry' => 30],
            ],
        ])->rows();

        self::assertSame(
            [
                'privacy.level',
                'privacy.retentionDays',
                'privacy.retention.conversation',
                'tools.dataClassEnforcement',
                'skills.minTrustLevel',
            ],
            array_map(static fn(EffectivePolicyRow $row): string => $row->key, $rows),
        );
        self::assertSame('30', $rows[1]->value);
        self::assertSame('7', $rows[2]->value);
        self::assertSame(PrivacyPolicy::class, $rows[2]->reader);
    }

    #[Test]
    public function aCategoryWindowIsNeverShownWithoutItsBaseline(): void
    {
        // The global row already reads "unknown"; a bare category number next
        // to it states nothing an operator could act on.
        $unreadable = $this->createMock(PrivacyPolicyInterface::class);
        $unreadable->method('level')->willReturn(PrivacyLevel::METADATA);
        $unreadable->method('retentionDays')->willThrowException(new RuntimeException('privacy resolver unavailable', 1785200003));
        $unreadable->method('retentionDaysFor')->willReturn(7);

        $rows = $this->readout($unreadable, [])->rows();

        self::assertFalse($rows[1]->isKnown());
        self::assertSame(
            ['privacy.level', 'privacy.retentionDays', 'tools.dataClassEnforcement', 'skills.minTrustLevel'],
            array_map(static fn(EffectivePolicyRow $row): string => $row->key, $rows),
        );
    }

    /**
     * A readout whose privacy rows come from the real {@see PrivacyPolicy}, so
     * the per-category fallback is the production one.
     *
     * @param array<string, mixed> $extensionConfigurationValue
     */
    private function readoutOnConfiguration(array $extensionConfigurationValue): EffectivePolicyReadout
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($extensionConfigurationValue);

        return new EffectivePolicyReadout(
            new PrivacyPolicy($extensionConfiguration, new ContentRedactor()),
            new DataClassEnforcementResolver($extensionConfiguration),
            new SkillComposerFactory($extensionConfiguration),
        );
    }

    /**
     * @param array<string, mixed> $extensionConfigurationValue
     */
    private function readout(
        PrivacyPolicyInterface $privacyPolicy,
        array $extensionConfigurationValue,
    ): EffectivePolicyReadout {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($extensionConfigurationValue);

        return new EffectivePolicyReadout(
            $privacyPolicy,
            new DataClassEnforcementResolver($extensionConfiguration),
            new SkillComposerFactory($extensionConfiguration),
        );
    }
}
