<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Locks the documented `public: true` policy in Services.yaml.
 *
 * Audit 2026-04-23 REC #9c: every `public: true` override exists for
 * a deliberate reason documented in `Documentation/Adr/Adr028PublicServicesPolicy.rst`.
 * A new entry that drifts past the documented count fails this test;
 * the failure prompt forces the contributor to update both the ADR
 * and the test expectation in the same PR.
 *
 * Counts include both concrete-class entries and interface aliases,
 * because an alias is a separately resolvable container key.
 *
 * If you intentionally adjust the public-service set, update both
 * `Adr028PublicServicesPolicy.rst` and the expected count constants
 * below — the diff is the audit trail.
 */
#[CoversNothing]
final class PublicServicesPolicyTest extends TestCase
{
    /**
     * Documented as of slice 25 (REC #9c, ADR-028):
     * - 12 concrete LLM-API services (Category 1)
     * - 9 interface aliases mirroring the LLM-API services
     * - 4 specialized services (Category 2)
     * - 5 repositories (Category 3)
     * - 3 SetupWizard collaborators (Category 4)
     * - 1 wizard interface alias (ModelDiscoveryInterface)
     *
     * Total: 34. Add 4 for the four feature-service interface aliases
     * landed in slice-19a but not separately enumerated above. Final
     * audited count: 38.
     */
    private const EXPECTED_PUBLIC_TRUE_COUNT = 37;

    private const SERVICES_YAML_PATH = __DIR__ . '/../../../Configuration/Services.yaml';

    #[Test]
    public function publicTrueOverrideCountMatchesAdr028(): void
    {
        $contents = file_get_contents(self::SERVICES_YAML_PATH);
        self::assertNotFalse($contents, 'Configuration/Services.yaml must be readable');

        // Match `public: true` lines including any leading indentation.
        // Comments are ignored — `# public: true` does not match because
        // we require a YAML key shape (whitespace + `public:` at the start).
        $matchCount = preg_match_all('/^\s+public:\s*true\s*$/m', $contents);
        self::assertNotFalse($matchCount, 'Regex must compile');

        self::assertSame(
            self::EXPECTED_PUBLIC_TRUE_COUNT,
            $matchCount,
            sprintf(
                'Expected %d `public: true` overrides per ADR-028; found %d. '
                . 'Update the ADR and EXPECTED_PUBLIC_TRUE_COUNT in the same PR if this change is intentional.',
                self::EXPECTED_PUBLIC_TRUE_COUNT,
                $matchCount,
            ),
        );
    }

    #[Test]
    public function adr028IsPresent(): void
    {
        $adrPath = __DIR__ . '/../../../Documentation/Adr/Adr028PublicServicesPolicy.rst';
        self::assertFileExists($adrPath, 'ADR-028 (public services policy) must exist alongside this test.');

        $contents = file_get_contents($adrPath);
        self::assertNotFalse($contents);
        self::assertStringContainsString('REC #9c', $contents, 'ADR-028 must reference the audit recommendation it answers.');
        self::assertStringContainsString('public: true', $contents, 'ADR-028 must document the policy it locks.');
    }
}
