<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Editorial;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Service\Editorial\ExternalTextCheck;
use Netresearch\NrLlm\Service\Editorial\ExternalTextReport;
use Netresearch\NrLlm\Service\Editorial\ExternalTextStatus;
use Netresearch\NrLlm\Service\Editorial\RuleStateDigest;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\Guardrail\Judge\JudgeEgressGate;
use Netresearch\NrLlm\Service\Guardrail\Judge\JudgeReentry;
use Netresearch\NrLlm\Service\Guardrail\Judge\RuleJudge;
use Netresearch\NrLlm\Service\Guardrail\Judge\RuleSetResolver;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListResolverInterface;
use Netresearch\NrLlm\Service\Guardrail\Term\TermMatcher;
use Netresearch\NrLlm\Service\Prompt\ConfigurationSnippetResolver;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

/**
 * The check of a delivered text against the real container and tables
 * (ADR-300, spec 057).
 *
 * The judging configuration of the fixture has no model, so every judge call
 * fails inside the real completion service: the path a timeout or an
 * unusable answer takes as well.
 */
#[CoversClass(ExternalTextCheck::class)]
#[CoversClass(RuleStateDigest::class)]
final class ExternalTextCheckWiringTest extends AbstractFunctionalTestCase
{
    /** Twelve words. */
    private const SHORT = 'Danke für den Hinweis, wir prüfen den Rabatt gern noch heute.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('RuleJudge.csv');
    }

    #[Test]
    public function aForbiddenTermOfAListInTheTableIsFoundInATwelveWordText(): void
    {
        $this->getConnection()->insert('tx_nrllm_term_list', ['uid' => 1, 'name' => 'Marke', 'configurations' => '4', 'forbidden_terms' => 'Rabatt']);

        $report = $this->check()->check(self::SHORT, $this->configuration(4));

        self::assertSame(ExternalTextStatus::VIOLATION, $report->status);
        self::assertSame('Rabatt', $report->findings[0]->subject);
    }

    #[Test]
    public function aJudgeThatMayNotReadTheTextIsNotCheckedInReportAndFailedInBlockMode(): void
    {
        $report = $this->check()->check(self::SHORT, $this->configuration(3));
        self::assertSame(ExternalTextStatus::NOT_CHECKED, $report->status);
        // The writer has no model, so its zone is the most trusted one, and a
        // judge without a model reaches no provider it could be checked against.
        self::assertSame([ExternalTextReport::REASON_JUDGE_EGRESS], $report->reasons);

        $blocked = $this->check()->check(self::SHORT, $this->configuration(1));
        self::assertSame(ExternalTextStatus::FAILED, $blocked->status);
        self::assertTrue($blocked->blocks());
    }

    #[Test]
    public function anEditedRuleSnippetChangesTheRuleState(): void
    {
        $digest = $this->digest();
        $before = $digest->of($this->configuration(3));

        $this->getConnection()->update('tx_nrllm_promptsnippet', ['snippet' => 'Sprich die Leser mit „du“ an.'], ['uid' => 1]);

        self::assertNotSame($before, $digest->of($this->configuration(3)));
    }

    #[Test]
    public function aNewForbiddenTermChangesTheRuleState(): void
    {
        $digest = $this->digest();
        $before = $digest->of($this->configuration(4));

        $this->getConnection()->insert('tx_nrllm_term_list', ['uid' => 2, 'name' => 'Marke', 'configurations' => '4', 'forbidden_terms' => 'Rabatt']);

        self::assertNotSame($before, $digest->of($this->configuration(4)));
    }

    /**
     * Built from the container's own collaborators: until the end-of-run
     * check (ADR-302) consumes it, the container drops the unused service.
     */
    private function check(): ExternalTextCheck
    {
        $configurations = $this->getService(LlmConfigurationRepository::class);

        return new ExternalTextCheck(
            $this->getService(TermListResolverInterface::class),
            $this->getService(TermMatcher::class),
            $this->getService(RuleSetResolver::class),
            $this->getService(RuleJudge::class),
            new JudgeEgressGate($this->getService(TrustZoneResolver::class), $this->getService(ProviderRepository::class), $configurations),
            $this->getService(JudgeReentry::class),
            $configurations,
            $this->digest(),
            new NullLogger(),
        );
    }

    private function digest(): RuleStateDigest
    {
        return new RuleStateDigest(
            $this->getService(LlmConfigurationRepository::class),
            $this->getService(ConfigurationSnippetResolver::class),
            $this->getService(RuleSetResolver::class),
            $this->getService(TermListResolverInterface::class),
        );
    }

    private function configuration(int $uid): LlmConfiguration
    {
        $configuration = $this->getService(LlmConfigurationRepository::class)->findByUid($uid);
        self::assertInstanceOf(LlmConfiguration::class, $configuration);

        return $configuration;
    }
}
