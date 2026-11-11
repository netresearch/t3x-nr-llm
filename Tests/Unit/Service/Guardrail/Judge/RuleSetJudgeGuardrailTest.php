<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Guardrail\Judge;

use Netresearch\NrLlm\Domain\Enum\GuardrailVerdict;
use Netresearch\NrLlm\Domain\Enum\RuleJudgeMode;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunReference;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailHint;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\CallMetadataFactory;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\Guardrail\Judge\JudgeReentry;
use Netresearch\NrLlm\Service\Guardrail\Judge\JudgeUnavailableException;
use Netresearch\NrLlm\Service\Guardrail\Judge\JudgeViolation;
use Netresearch\NrLlm\Service\Guardrail\Judge\RuleJudge;
use Netresearch\NrLlm\Service\Guardrail\Judge\RuleJudgeMessages;
use Netresearch\NrLlm\Service\Guardrail\Judge\RuleSet;
use Netresearch\NrLlm\Service\Guardrail\Judge\RuleSetJudgeGuardrail;
use Netresearch\NrLlm\Service\Guardrail\Judge\RuleSetResolver;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListResolverInterface;
use Netresearch\NrLlm\Service\Guardrail\Term\TermMatcher;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

use function str_ends_with;

/**
 * Off, report and block, and what a failed check does in each (ADR-288).
 */
#[CoversClass(RuleSetJudgeGuardrail::class)]
#[CoversClass(RuleJudgeMessages::class)]
final class RuleSetJudgeGuardrailTest extends AbstractUnitTestCase
{
    private const TEXT = 'Hallo du, hier ist dein Angebot für den Sommer. Wir zeigen dir in diesem Text, wie du mit wenigen Schritten eine neue Seite anlegst, sie mit Inhalt füllst und am Ende für alle Besucher deiner Website freigibst, ohne lange zu suchen.';

    private int $judgeCalls = 0;

    #[Test]
    public function offDoesNotCallTheJudge(): void
    {
        $result = $this->guardrail([$this->violation()])->checkOutputInContext($this->response(), $this->call(RuleJudgeMode::OFF));

        self::assertSame(GuardrailVerdict::ALLOW, $result->verdict);
        self::assertSame([], $result->hints);
        self::assertSame(0, $this->judgeCalls);
    }

    #[Test]
    public function reportDeliversTheTextWithAHintNamingTheRuleAndThePassage(): void
    {
        $result = $this->guardrail([$this->violation()])->checkOutputInContext($this->response(), $this->call(RuleJudgeMode::REPORT));

        self::assertSame(GuardrailVerdict::ALLOW, $result->verdict);
        self::assertCount(1, $result->hints);
        self::assertSame(GuardrailHint::KIND_VIOLATION, $result->hints[0]->kind);
        self::assertSame('Sprich die Leser mit „Sie“ an.', $result->hints[0]->rule);
        self::assertSame('Hallo du', $result->hints[0]->passage);
        self::assertSame('rule "Sprich die Leser mit „Sie“ an." in "Ton": "Hallo du"', $result->hints[0]->message);
        self::assertSame(RuleSetJudgeGuardrail::IDENTIFIER, $result->hints[0]->guardrail);
    }

    #[Test]
    public function reportPassesACompliantTextWithoutAHint(): void
    {
        $result = $this->guardrail([])->checkOutputInContext($this->response(), $this->call(RuleJudgeMode::REPORT));

        self::assertSame(GuardrailVerdict::ALLOW, $result->verdict);
        self::assertSame([], $result->hints);
        self::assertSame(1, $this->judgeCalls);
    }

    #[Test]
    public function reportDeliversTheTextWhenTheCheckFailsAndSaysItWasNotChecked(): void
    {
        $result = $this->guardrail(new JudgeUnavailableException('timeout'))->checkOutputInContext($this->response(), $this->call(RuleJudgeMode::REPORT));

        self::assertSame(GuardrailVerdict::ALLOW, $result->verdict);
        self::assertCount(1, $result->hints);
        self::assertSame(GuardrailHint::KIND_UNAVAILABLE, $result->hints[0]->kind);
        self::assertSame('not checked', $result->hints[0]->message);
    }

    #[Test]
    public function reportDeliversTheTextWhenTheCheckFailsUnexpectedly(): void
    {
        $result = $this->guardrail(new RuntimeException('database gone'))->checkOutputInContext($this->response(), $this->call(RuleJudgeMode::REPORT));

        self::assertSame(GuardrailVerdict::ALLOW, $result->verdict);
        self::assertSame(GuardrailHint::KIND_UNAVAILABLE, $result->hints[0]->kind);
    }

    #[Test]
    public function blockStopsTheTextAndQuotesRuleAndPassageButStoresNoPassage(): void
    {
        $result = $this->guardrail([$this->violation()])->checkOutputInContext($this->response(), $this->call(RuleJudgeMode::BLOCK));

        self::assertSame(GuardrailVerdict::DENY, $result->verdict);
        self::assertSame('blocked: Sprich die Leser mit „Sie“ an. in Ton: <Hallo du>', $result->reason);
        self::assertSame('The rule-set judge found these rules broken: 1 "Sprich die Leser mit „Sie“ an." (snippet "Ton")', $result->auditReason);
        self::assertStringNotContainsString('Hallo du', $result->auditReason);
    }

    #[Test]
    public function blockNamesARuleOnceWithAllItsPassages(): void
    {
        $second = new JudgeViolation($this->violation()->rule, 'dein Angebot');

        $result = $this->guardrail([$this->violation(), $second])->checkOutputInContext($this->response(), $this->call(RuleJudgeMode::BLOCK));

        self::assertSame('blocked: Sprich die Leser mit „Sie“ an. in Ton: <Hallo du>, <dein Angebot>', $result->reason);
        self::assertSame('The rule-set judge found these rules broken: 1 "Sprich die Leser mit „Sie“ an." (snippet "Ton")', $result->auditReason);
    }

    #[Test]
    public function blockPassesACompliantText(): void
    {
        self::assertSame(GuardrailVerdict::ALLOW, $this->guardrail([])->checkOutputInContext($this->response(), $this->call(RuleJudgeMode::BLOCK))->verdict);
    }

    #[Test]
    public function blockStopsTheTextWhenTheCheckFails(): void
    {
        $result = $this->guardrail(new JudgeUnavailableException('unparseable'))->checkOutputInContext($this->response(), $this->call(RuleJudgeMode::BLOCK));

        self::assertSame(GuardrailVerdict::DENY, $result->verdict);
        self::assertSame('blocked unavailable', $result->reason);
    }

    #[Test]
    public function withoutAJudgingConfigurationTheCheckIsUnavailable(): void
    {
        $guardrail = $this->guardrail([$this->violation()]);
        $call      = $this->call(RuleJudgeMode::BLOCK, judging: 0);

        self::assertSame('blocked unavailable', $guardrail->checkOutputInContext($this->response(), $call)->reason);
        self::assertSame(0, $this->judgeCalls);
    }

    #[Test]
    public function aConfigurationCannotJudgeItself(): void
    {
        $this->guardrail([$this->violation()])->checkOutputInContext($this->response(), $this->call(RuleJudgeMode::BLOCK, judging: 3));

        self::assertSame(0, $this->judgeCalls);
    }

    #[Test]
    public function aJudgeOnALessTrustedProviderIsNotAsked(): void
    {
        $guardrail = $this->guardrail([$this->violation()], judgingZone: TrustZone::EXTERNAL_GLOBAL);

        $result = $guardrail->checkOutputInContext($this->response(), $this->call(RuleJudgeMode::REPORT, ownZone: TrustZone::LOCAL));

        self::assertSame(GuardrailHint::KIND_UNAVAILABLE, $result->hints[0]->kind);
        self::assertSame(0, $this->judgeCalls);
    }

    #[Test]
    public function aJudgeOnAMoreTrustedProviderIsAsked(): void
    {
        $guardrail = $this->guardrail([$this->violation()], judgingZone: TrustZone::LOCAL);

        $result = $guardrail->checkOutputInContext($this->response(), $this->call(RuleJudgeMode::REPORT, ownZone: TrustZone::EXTERNAL_EU));

        self::assertSame(GuardrailHint::KIND_VIOLATION, $result->hints[0]->kind);
    }

    #[Test]
    public function aRuleSnippetTheJudgingZoneMayNotReceiveStopsTheCheck(): void
    {
        $guardrail = $this->guardrail([$this->violation()], judgingZone: TrustZone::EXTERNAL_GLOBAL, ruleClass: ToolDataClass::SECRET_ADJACENT);

        $result = $guardrail->checkOutputInContext($this->response(), $this->call(RuleJudgeMode::REPORT));

        self::assertSame(GuardrailHint::KIND_UNAVAILABLE, $result->hints[0]->kind);
        self::assertSame(0, $this->judgeCalls);
    }

    /**
     * @return iterable<string, array{CompletionResponse, ProviderOperation, ?AgentRunReference}>
     */
    public static function outputsThatAreNotJudged(): iterable
    {
        $usage = UsageStatistics::fromTokens(1, 1);

        yield 'a short answer' => [new CompletionResponse('Hallo du', 'm', $usage), ProviderOperation::Chat, null];
        yield 'a tool round' => [new CompletionResponse(self::TEXT, 'm', $usage, toolCalls: [new ToolCall('c', 'write', ['text' => self::TEXT])]), ProviderOperation::Tools, null];
        yield 'an image description' => [new CompletionResponse(self::TEXT, 'm', $usage), ProviderOperation::Vision, null];
        yield 'a step of an agent run' => [new CompletionResponse(self::TEXT, 'm', $usage), ProviderOperation::Chat, new AgentRunReference(5, 'u')];
    }

    #[Test]
    #[DataProvider('outputsThatAreNotJudged')]
    public function onlyAFinishedTextIsJudged(CompletionResponse $response, ProviderOperation $operation, ?AgentRunReference $run): void
    {
        $result = $this->guardrail([$this->violation()])->checkOutputInContext($response, $this->call(RuleJudgeMode::BLOCK, operation: $operation, run: $run));

        self::assertSame(GuardrailVerdict::ALLOW, $result->verdict);
        self::assertSame(0, $this->judgeCalls);
    }

    #[Test]
    public function aJsonAnswerIsJudgedByItsStrings(): void
    {
        $judged = '';
        $judge  = self::createStub(RuleJudge::class);
        $judge->method('judge')->willReturnCallback(function (string $text) use (&$judged): array {
            $judged = $text;

            return [];
        });

        $json = '{"sections":[{"headline":"Sommer","body":"' . self::TEXT . '"}]}';
        $this->guardrail([], judge: $judge)->checkOutputInContext(new CompletionResponse($json, 'm', UsageStatistics::fromTokens(1, 1)), $this->call(RuleJudgeMode::REPORT));

        self::assertSame("Sommer\n\n" . self::TEXT, $judged);
    }

    #[Test]
    public function theJudgesOwnCallIsNotJudged(): void
    {
        $reentry   = new JudgeReentry();
        $guardrail = $this->guardrail([$this->violation()], reentry: $reentry);

        $result = $reentry->during(fn (): GuardrailResult => $guardrail->checkOutputInContext($this->response(), $this->call(RuleJudgeMode::BLOCK)));

        self::assertSame(GuardrailVerdict::ALLOW, $result->verdict);
        self::assertSame(0, $this->judgeCalls);
        self::assertFalse($reentry->during(fn (): bool => $guardrail->holdsStream($this->call(RuleJudgeMode::BLOCK))));
        self::assertFalse($reentry->isActive());
    }

    #[Test]
    public function blockHoldsAStreamAndReportAnnotatesIt(): void
    {
        $guardrail = $this->guardrail([]);

        self::assertTrue($guardrail->holdsStream($this->call(RuleJudgeMode::BLOCK)));
        self::assertFalse($guardrail->annotatesStream($this->call(RuleJudgeMode::BLOCK)));
        self::assertFalse($guardrail->holdsStream($this->call(RuleJudgeMode::REPORT)));
        self::assertTrue($guardrail->annotatesStream($this->call(RuleJudgeMode::REPORT)));
        self::assertFalse($guardrail->holdsStream($this->call(RuleJudgeMode::OFF)));
        self::assertFalse($guardrail->annotatesStream($this->call(RuleJudgeMode::OFF)));
    }

    #[Test]
    public function aStreamOfARunThatOffersToolsIsNeitherHeldNorAnnotated(): void
    {
        $guardrail = $this->guardrail([]);
        $run       = (new AgentRunReference(5, 'u'))->offeringTools(['search']);

        self::assertFalse($guardrail->holdsStream($this->call(RuleJudgeMode::BLOCK, run: $run)));
        self::assertFalse($guardrail->annotatesStream($this->call(RuleJudgeMode::REPORT, run: $run)));
    }

    #[Test]
    public function withoutAContextNothingIsJudged(): void
    {
        self::assertSame(GuardrailVerdict::ALLOW, $this->guardrail([$this->violation()])->checkOutput($this->response())->verdict);
        self::assertSame(0, $this->judgeCalls);
    }

    #[Test]
    public function theGuardrailIsMandatory(): void
    {
        $guardrail = $this->guardrail([]);

        self::assertTrue($guardrail->isMandatory());
        self::assertSame('rule-set-judge', $guardrail->getIdentifier());
    }

    /**
     * @param list<JudgeViolation>|Throwable $verdict
     */
    private function guardrail(
        array|Throwable $verdict,
        TrustZone $judgingZone = TrustZone::EXTERNAL_GLOBAL,
        ?ToolDataClass $ruleClass = null,
        ?RuleJudge $judge = null,
        ?JudgeReentry $reentry = null,
    ): RuleSetJudgeGuardrail {
        $ruleSets = self::createStub(RuleSetResolver::class);
        $ruleSets->method('forTags')->willReturn(RuleSet::fromSnippets([['name' => 'Ton', 'text' => 'Sprich die Leser mit „Sie“ an.', 'dataClass' => $ruleClass]]));

        if (!$judge instanceof RuleJudge) {
            $judge = self::createStub(RuleJudge::class);
            $judge->method('judge')->willReturnCallback(function () use ($verdict): array {
                ++$this->judgeCalls;
                if ($verdict instanceof Throwable) {
                    throw $verdict;
                }

                return $verdict;
            });
        }

        $judging        = $this->configuration(7, $judgingZone);
        $configurations = self::createStub(LlmConfigurationRepository::class);
        $configurations->method('findByUid')->willReturnCallback(static fn (int $uid): ?LlmConfiguration => $uid === 7 ? $judging : null);

        $language = self::createStub(LanguageService::class);
        $language->method('sL')->willReturnCallback(static fn (string $key): string => match (true) {
            str_ends_with($key, 'ruleJudge.hint.violation')      => 'rule "%1$s" in "%2$s": "%3$s"',
            str_ends_with($key, 'ruleJudge.hint.unavailable')    => 'not checked',
            str_ends_with($key, 'ruleJudge.blocked.violation')   => 'blocked: %s',
            str_ends_with($key, 'ruleJudge.blocked.rule')        => '%1$s in %2$s: %3$s',
            str_ends_with($key, 'ruleJudge.blocked.passage')     => '<%s>',
            str_ends_with($key, 'ruleJudge.blocked.unavailable') => 'blocked unavailable',
            default                                              => '',
        });
        $factory = self::createStub(LanguageServiceFactory::class);
        $factory->method('createFromUserPreferences')->willReturn($language);
        $factory->method('create')->willReturn($language);

        return new RuleSetJudgeGuardrail(
            $ruleSets,
            $judge,
            new RuleJudgeMessages($factory),
            new TermMatcher(),
            $configurations,
            new TrustZoneResolver(),
            self::createStub(TermListResolverInterface::class),
            $reentry ?? new JudgeReentry(),
            new RecordingLogger(),
        );
    }

    private function call(
        RuleJudgeMode $mode,
        int $judging = 7,
        TrustZone $ownZone = TrustZone::EXTERNAL_GLOBAL,
        ProviderOperation $operation = ProviderOperation::Chat,
        ?AgentRunReference $run = null,
    ): ProviderCallContext {
        $configuration = $this->configuration(3, $ownZone);
        $configuration->setRuleJudgeMode($mode->value);
        $configuration->setRuleJudgeTags('tone');
        $configuration->setRuleJudgeConfiguration($judging);

        return new ProviderCallContext($operation, 'c', $configuration, metadata: (new CallMetadataFactory())->agentRun($run));
    }

    private function configuration(int $uid, TrustZone $zone): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum($zone);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->_setProperty('uid', $uid);
        $configuration->setLlmModel($model);
        $configuration->setIsActive(true);

        return $configuration;
    }

    private function violation(): JudgeViolation
    {
        $rules = RuleSet::fromSnippets([['name' => 'Ton', 'text' => 'Sprich die Leser mit „Sie“ an.', 'dataClass' => null]]);

        return new JudgeViolation($rules->rules[0], 'Hallo du');
    }

    private function response(): CompletionResponse
    {
        return new CompletionResponse(self::TEXT, 'm', UsageStatistics::fromTokens(1, 1));
    }
}
