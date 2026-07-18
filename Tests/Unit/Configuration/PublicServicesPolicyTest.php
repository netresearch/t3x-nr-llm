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
     * Audited count of `public: true` overrides (REC #9c, ADR-028,
     * reduced by ADR-065, further reduced by ADR-069 which removed the
     * unusable PromptTemplate stack). ADR-065 privatised every service
     * that was public *only* so functional tests could resolve it via
     * `FunctionalTestCase::get()` — the testing framework's
     * private-container pass (`PrivateContainerWeakRefPass`) already
     * makes every private service resolvable through `get()`, so those
     * overrides were never load-bearing. Categories per ADR-065:
     *
     * - Category A (Documented downstream LLM-API contract): 7 concrete
     *   services + 7 interface aliases + 2 keyword-search facade entries
     *   (ADR-071: KeywordSearchInterface alias and the named
     *   `nr_llm.keyword_search.index_backed` variant) + 1 reranker
     *   protocol entry (ADR-075: RerankerInterface, factory-built), plus the
     *   ConversationService concrete + ConversationServiceInterface alias
     *   (ADR-083: a stateful feature service beside Completion) = 19 —
     *   LlmServiceManager, ProviderAdapterRegistry, and the
     *   Completion/Vision/Embedding/Translation/ToolCalling (ADR-051)
     *   feature pairs.
     * - Category B (Supporting-service interface aliases; the concrete
     *   classes are now private): 5 — CacheManagerInterface,
     *   UsageTrackerServiceInterface, TranslatorRegistryInterface,
     *   LlmConfigurationServiceInterface, BudgetServiceInterface.
     * - Category C (Concrete-only documented surface): 1 —
     *   PromptSnippetComposer (ADR-031, no interface).
     * - Category D (Specialized standalone consumer API): 5 —
     *   Whisper, TextToSpeech, DallE, Fal, DocumentAnalysis (ADR-076).
     * - Category E (resolved outside DI via makeInstance()): 2 —
     *   ToolRegistry (TCA itemsProcFunc, ADR-042) and ProviderDetector
     *   (ProviderEndpointNormalizationHook, a DataHandler hook).
     *
     * Total: 19 + 5 + 1 + 5 + 2 = **32**.
     *
     * To intentionally change this number: update both this
     * constant AND the matching breakdown in
     * `Documentation/Adr/Adr083ConversationSessions.rst` (the current
     * count authority, superseding ADR-075's count) in the same PR — the
     * diff is the audit trail.
     */
    private const EXPECTED_PUBLIC_TRUE_COUNT = 32;

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

    #[Test]
    public function adr065IsPresent(): void
    {
        $adrPath = __DIR__ . '/../../../Documentation/Adr/Adr065ReducePublicServiceSurface.rst';
        self::assertFileExists($adrPath, 'ADR-065 (public service surface reduction) must exist alongside this test.');

        $contents = file_get_contents($adrPath);
        self::assertNotFalse($contents);
        self::assertStringContainsString('PrivateContainerWeakRefPass', $contents, 'ADR-065 must document the test-container mechanism it relies on.');
        self::assertStringContainsString('27', $contents, 'ADR-065 must record the reduced public-service count.');
    }
}
