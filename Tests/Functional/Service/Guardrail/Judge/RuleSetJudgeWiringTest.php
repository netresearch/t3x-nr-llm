<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Guardrail\Judge;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use Netresearch\NrLlm\Service\Guardrail\GuardrailHintCollector;
use Netresearch\NrLlm\Domain\Enum\RuleJudgeMode;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailHint;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Provider\Middleware\GuardrailMiddleware;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\Guardrail\GuardrailRegistry;
use Netresearch\NrLlm\Service\Guardrail\Judge\Rule;
use Netresearch\NrLlm\Service\Guardrail\Judge\RuleSetJudgeGuardrail;
use Netresearch\NrLlm\Service\Guardrail\Judge\RuleSetResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Streaming\StreamingDispatcher;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use function array_map;

/**
 * The switch on a configuration, the rule set from the snippet library, and a
 * judge that cannot answer, against the real container and tables (ADR-288).
 *
 * The judging configuration of the fixture has no model, so every judge call
 * fails inside the real completion service: the path a timeout, an error or an
 * unparseable answer takes as well.
 */
#[CoversClass(RuleSetJudgeGuardrail::class)]
#[CoversClass(RuleSetResolver::class)]
final class RuleSetJudgeWiringTest extends AbstractFunctionalTestCase
{
    private const TEXT = 'Hallo du, hier ist dein Angebot für den Sommer. Wir zeigen dir in diesem Text, wie du mit wenigen Schritten eine neue Seite anlegst, sie mit Inhalt füllst und am Ende für alle Besucher deiner Website freigibst, ohne lange zu suchen.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('RuleJudge.csv');
    }

    #[Test]
    public function theSwitchIsStoredOnTheConfigurationAndOffByDefault(): void
    {
        $repository = $this->getService(LlmConfigurationRepository::class);

        $block = $repository->findByUid(1);
        self::assertInstanceOf(LlmConfiguration::class, $block);
        self::assertSame(RuleJudgeMode::BLOCK, $block->getRuleJudgeModeEnum());
        self::assertSame(['rules'], $block->getRuleJudgeTagList());
        self::assertSame(2, $block->getRuleJudgeConfiguration());

        $report = $repository->findByUid(3);
        self::assertInstanceOf(LlmConfiguration::class, $report);
        self::assertSame(RuleJudgeMode::REPORT, $report->getRuleJudgeModeEnum());
        self::assertSame(['rules'], $report->getRuleJudgeTagList());

        self::assertSame(RuleJudgeMode::OFF, (new LlmConfiguration())->getRuleJudgeModeEnum());
        $this->getConnection()->insert('tx_nrllm_configuration', ['uid' => 9, 'identifier' => 'fresh', 'name' => 'Fresh', 'is_active' => 1]);
        $fresh = $repository->findByUid(9);
        self::assertInstanceOf(LlmConfiguration::class, $fresh);
        self::assertSame(RuleJudgeMode::OFF, $fresh->getRuleJudgeModeEnum());
    }

    #[Test]
    public function theRuleSetIsTheVisibleActiveSnippetsWithTheTagLineByLine(): void
    {
        $rules = $this->getService(RuleSetResolver::class)->forTags(['rules']);

        self::assertSame(
            [[1, 'Sprich die Leser mit „Sie“ an.', 'Ton'], [2, 'Keine Superlative.', 'Ton'], [3, 'Nenne keine Preise ohne Mehrwertsteuer.', 'Recht']],
            array_map(static fn (Rule $rule): array => [$rule->number, $rule->text, $rule->snippetName], $rules->rules),
        );
        self::assertTrue($this->getService(RuleSetResolver::class)->forTags([])->isEmpty());
    }

    #[Test]
    public function theGuardrailIsCollectedAndCannotBeDeselected(): void
    {
        $registry = $this->getService(GuardrailRegistry::class);

        self::assertTrue($registry->isMandatoryIdentifier('rule-set-judge'));
        self::assertNotContains('rule-set-judge', $registry->selectableIdentifiers());
    }

    #[Test]
    public function theContainerBuildsTheStreamingAndTheCallPathAroundTheJudge(): void
    {
        // The judge needs the completion service, which needs the guardrails:
        // both must still be buildable.
        self::assertInstanceOf(StreamingDispatcher::class, $this->getService(StreamingDispatcher::class));
        self::assertInstanceOf(LlmServiceManagerInterface::class, $this->getService(LlmServiceManagerInterface::class));
    }

    /**
     * A consumer resolving the collector by class name gets the instance the
     * middleware records into, so its capture sees the hints (ADR-288).
     */
    #[Test]
    public function aCaptureThroughMakeInstanceSeesTheHintsOfTheMiddleware(): void
    {
        $collector = GeneralUtility::makeInstance(GuardrailHintCollector::class);
        self::assertSame($this->getService(GuardrailHintCollector::class), $collector);

        $captured = $collector->capture(fn (): mixed => $this->screen(3));

        self::assertSame([GuardrailHint::KIND_UNAVAILABLE], array_map(static fn (GuardrailHint $hint): string => $hint->kind, $captured['hints']));
    }

    #[Test]
    public function reportDeliversTheTextWhenTheJudgeCannotAnswer(): void
    {
        $result = $this->screen(3);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertSame(self::TEXT, $result->content);
        $hints = GuardrailHint::fromMetadata($result->metadata);
        self::assertCount(1, $hints);
        self::assertSame(GuardrailHint::KIND_UNAVAILABLE, $hints[0]->kind);
        self::assertSame('rule-set-judge', $hints[0]->guardrail);
    }

    #[Test]
    public function blockStopsTheTextWhenTheJudgeCannotAnswerAndRecordsIt(): void
    {
        try {
            $this->screen(1);
            self::fail('Block mode delivered a text nobody could check.');
        } catch (GuardrailViolationException $blocked) {
            self::assertStringContainsString('rule set', $blocked->getMessage());
        }

        self::assertSame(1, (int) $this->getConnection()->fetchOne('SELECT COUNT(*) FROM tx_nrllm_governance_event'));
        $detail = $this->getConnection()->fetchOne('SELECT detail FROM tx_nrllm_governance_event');
        self::assertIsString($detail);
        self::assertStringNotContainsString('Hallo du', $detail);
    }

    #[Test]
    public function offLeavesTheTextAlone(): void
    {
        $result = $this->screen(4);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertSame([], GuardrailHint::fromMetadata($result->metadata));
    }

    #[Test]
    public function aTermListStopsTheTextBeforeTheJudgeIsAsked(): void
    {
        $this->getConnection()->insert('tx_nrllm_term_list', [
            'uid'             => 1,
            'name'            => 'Marke',
            'configurations'  => '1',
            'forbidden_terms' => 'Angebot',
        ]);

        try {
            $this->screen(1);
            self::fail('Not blocked.');
        } catch (GuardrailViolationException $blocked) {
            // The term list's sentence, not the judge's: the judge runs last.
            self::assertStringContainsString('Angebot', $blocked->getMessage());
            self::assertStringNotContainsString('rule set', $blocked->getMessage());
        }
    }

    private function screen(int $configurationUid): mixed
    {
        $configuration = $this->getService(LlmConfigurationRepository::class)->findByUid($configurationUid);
        self::assertInstanceOf(LlmConfiguration::class, $configuration);

        return $this->getService(GuardrailMiddleware::class)->handle(
            ProviderCallContext::forConfiguration(ProviderOperation::Chat, $configuration),
            static fn (): CompletionResponse => new CompletionResponse(self::TEXT, 'm', UsageStatistics::fromTokens(1, 1)),
        );
    }
}
