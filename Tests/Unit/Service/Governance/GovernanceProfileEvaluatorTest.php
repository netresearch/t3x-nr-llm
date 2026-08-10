<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Governance;

use Netresearch\NrLlm\Service\Governance\EffectivePolicyRow;
use Netresearch\NrLlm\Service\Governance\GovernanceProfile;
use Netresearch\NrLlm\Service\Governance\GovernanceProfileDeviation;
use Netresearch\NrLlm\Service\Governance\GovernanceProfileEvaluator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GovernanceProfileEvaluator::class)]
final class GovernanceProfileEvaluatorTest extends TestCase
{
    #[Test]
    public function anInstallationMatchingTheProfileHasNoDeviations(): void
    {
        $rows = $this->rows([
            'privacy.level'              => 'metadata',
            'privacy.retentionDays'      => '7',
            'tools.dataClassEnforcement' => 'enforce',
            'skills.minTrustLevel'       => 'first_party',
        ]);

        $evaluator = new GovernanceProfileEvaluator();

        self::assertSame([], $evaluator->deviations($rows, GovernanceProfile::ENTERPRISE_STRICT));
        self::assertTrue($evaluator->isCompliant($rows, GovernanceProfile::ENTERPRISE_STRICT));
    }

    #[Test]
    public function eachDifferingKeyIsReportedWithBothValuesAndWhereToChangeIt(): void
    {
        $deviations = (new GovernanceProfileEvaluator())->deviations($this->rows([
            'privacy.level'              => 'full',
            'privacy.retentionDays'      => '7',
            'tools.dataClassEnforcement' => 'observe',
            'skills.minTrustLevel'       => 'first_party',
        ]), GovernanceProfile::ENTERPRISE_STRICT);

        self::assertSame(
            ['privacy.level', 'tools.dataClassEnforcement'],
            array_map(static fn(GovernanceProfileDeviation $d): string => $d->key, $deviations),
        );
        self::assertSame('full', $deviations[0]->current);
        self::assertSame('metadata', $deviations[0]->expected);
        self::assertNotSame('', $deviations[0]->whereKey, 'a deviation without a place to fix it is half an answer');
    }

    #[Test]
    public function aKeyTheProfileSaysNothingAboutIsNotCompared(): void
    {
        // Silence is a position. A profile with an opinion on every key would
        // force operators to disagree with it about things it never described.
        $deviations = (new GovernanceProfileEvaluator())->deviations($this->rows([
            'privacy.level'         => 'metadata',
            'privacy.retentionDays' => '7',
            'privacy.retention.chat' => '365',
        ]), GovernanceProfile::ENTERPRISE_STRICT);

        self::assertSame(
            [],
            array_filter($deviations, static fn(GovernanceProfileDeviation $d): bool => $d->key === 'privacy.retention.chat'),
        );
    }

    #[Test]
    public function anUnreadableValueIsADeviationThatSaysSo(): void
    {
        // A resolver that could not be asked is a different problem from a
        // deliberate divergence, and telling an operator to change a setting
        // they may already have set right would waste their time.
        $deviations = (new GovernanceProfileEvaluator())->deviations(
            $this->rows(['privacy.level' => null]),
            GovernanceProfile::ENTERPRISE_STRICT,
        );

        self::assertCount(1, $deviations);
        self::assertTrue($deviations[0]->isUnknown());
        self::assertNull($deviations[0]->current);
    }

    #[Test]
    public function everyProfileDescribesOnlyKeysTheReadoutReports(): void
    {
        // A profile naming a key nothing reports would silently describe
        // nothing — the deviation could never appear, so the expectation could
        // never be met or missed.
        $reported = [
            'privacy.level',
            'privacy.retentionDays',
            'tools.dataClassEnforcement',
            'skills.minTrustLevel',
        ];

        foreach (GovernanceProfile::cases() as $profile) {
            foreach (array_keys($profile->expectations()) as $key) {
                self::assertContains($key, $reported, sprintf('%s expects an unreported key "%s"', $profile->value, $key));
            }
        }
    }

    /**
     * @param array<string, string|null> $values
     *
     * @return list<EffectivePolicyRow>
     */
    private function rows(array $values): array
    {
        $rows = [];
        foreach ($values as $key => $value) {
            $rows[] = new EffectivePolicyRow($key, $value, 'TestReader');
        }

        return $rows;
    }
}
