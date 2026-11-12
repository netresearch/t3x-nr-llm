<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Guardrail\Judge;

use Netresearch\NrLlm\Domain\Enum\GuardrailVerdict;
use Netresearch\NrLlm\Domain\Enum\RuleJudgeMode;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailHint;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\Guardrail\Judge\JudgeReentry;
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
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

use function in_array;
use function str_contains;
use function str_ends_with;

/**
 * A caller that names further configurations (ADR-290) gets their judges as
 * well: each side is judged with its own rules by its own judging
 * configuration, and the strictest mode decides.
 */
#[CoversClass(RuleSetJudgeGuardrail::class)]
final class RuleSetJudgeGuardrailConfigurationsTest extends AbstractUnitTestCase
{
    private const TEXT = 'Hallo du, hier ist dein Angebot für den Sommer. Wir zeigen dir in diesem Text, wie du mit wenigen Schritten eine neue Seite anlegst, sie mit Inhalt füllst und am Ende für alle Besucher deiner Website freigibst, ohne lange zu suchen.';

    /** The configuration the call runs on (the site's). */
    private const OWN = 3;

    /** The configuration the caller names (the chat Task's). */
    private const NAMED = 4;

    /** The judging configurations of the two sides. */
    private const OWN_JUDGE = 7;

    private const NAMED_JUDGE = 8;

    /** @var list<int> uid of the judging configuration of each judge call */
    private array $judgedBy = [];

    /** @var array<int, string> rule tags a side asked for, by judging uid */
    private array $tagsBy = [];

    private bool $namedActive = true;

    private RuleJudgeMode $namedMode = RuleJudgeMode::OFF;

    /** The zone of the provider of the configuration the call runs on. */
    private TrustZone $ownZone = TrustZone::EXTERNAL_GLOBAL;

    #[Test]
    public function aBlockingJudgeOfTheNamedConfigurationBlocksWhereTheOwnIsOff(): void
    {
        $result = $this->guardrail()->checkOutputInContext($this->response(), $this->call(RuleJudgeMode::OFF, RuleJudgeMode::BLOCK));

        self::assertSame(GuardrailVerdict::DENY, $result->verdict);
        self::assertSame([self::NAMED_JUDGE], $this->judgedBy);
    }

    #[Test]
    public function theStricterModeWinsInBothDirections(): void
    {
        self::assertSame(GuardrailVerdict::DENY, $this->guardrail()->checkOutputInContext($this->response(), $this->call(RuleJudgeMode::REPORT, RuleJudgeMode::BLOCK))->verdict);
        self::assertSame(GuardrailVerdict::DENY, $this->guardrail()->checkOutputInContext($this->response(), $this->call(RuleJudgeMode::BLOCK, RuleJudgeMode::REPORT))->verdict);
    }

    #[Test]
    public function aReportingJudgeOfTheNamedConfigurationReportsWhereTheOwnIsOff(): void
    {
        $result = $this->guardrail()->checkOutputInContext($this->response(), $this->call(RuleJudgeMode::OFF, RuleJudgeMode::REPORT));

        self::assertSame(GuardrailVerdict::ALLOW, $result->verdict);
        self::assertSame(GuardrailHint::KIND_VIOLATION, $result->hints[0]->kind ?? null);
    }

    /**
     * Each side asks its own judge about its own rules; the same finding is reported once.
     */
    #[Test]
    public function bothSidesAreJudgedByTheirOwnJudgeAndAFindingIsNamedOnce(): void
    {
        $result = $this->guardrail()->checkOutputInContext($this->response(), $this->call(RuleJudgeMode::REPORT, RuleJudgeMode::REPORT));

        self::assertSame([self::OWN_JUDGE, self::NAMED_JUDGE], $this->judgedBy);
        self::assertSame([self::OWN_JUDGE => 'site', self::NAMED_JUDGE => 'chat'], $this->tagsBy);
        self::assertCount(1, $result->hints);
    }

    /**
     * A failed check of either side counts under the stricter mode.
     */
    #[Test]
    public function aFailedCheckOfTheNamedSideBlocksUnderTheStricterMode(): void
    {
        $result = $this->guardrail(failing: self::NAMED_JUDGE)->checkOutputInContext($this->response(), $this->call(RuleJudgeMode::REPORT, RuleJudgeMode::BLOCK));

        self::assertSame(GuardrailVerdict::DENY, $result->verdict);
        self::assertSame('blocked unavailable', $result->reason);
    }

    /**
     * The text was written on the call's own configuration, so the named
     * side's judge is measured against that provider, not against the named
     * configuration's: a site on a local provider never has its text sent to
     * the chat configuration's external judge.
     */
    #[Test]
    public function theNamedJudgeIsMeasuredAgainstTheProviderThatWroteTheText(): void
    {
        $this->ownZone = TrustZone::LOCAL;

        $blocked  = $this->guardrail()->checkOutputInContext($this->response(), $this->call(RuleJudgeMode::OFF, RuleJudgeMode::BLOCK));
        $reported = $this->guardrail()->checkOutputInContext($this->response(), $this->call(RuleJudgeMode::OFF, RuleJudgeMode::REPORT));

        self::assertSame([], $this->judgedBy, 'the external judge must not receive the text');
        self::assertSame(GuardrailVerdict::DENY, $blocked->verdict);
        self::assertSame('blocked unavailable', $blocked->reason);
        self::assertSame(GuardrailVerdict::ALLOW, $reported->verdict);
        self::assertSame(GuardrailHint::KIND_UNAVAILABLE, $reported->hints[0]->kind ?? null);
    }

    /**
     * Switched off, the chat's configuration still adds its check, as its term lists still apply.
     */
    #[Test]
    public function anInactiveNamedConfigurationStillJudges(): void
    {
        $this->namedActive = false;

        $result = $this->guardrail()->checkOutputInContext($this->response(), $this->call(RuleJudgeMode::OFF, RuleJudgeMode::BLOCK));

        self::assertSame(GuardrailVerdict::DENY, $result->verdict);
        self::assertSame([self::NAMED_JUDGE], $this->judgedBy);
    }

    #[Test]
    public function aNamedConfigurationThatIsGoneJudgesNothing(): void
    {
        $result = $this->guardrail()->checkOutputInContext($this->response(), $this->call(RuleJudgeMode::OFF, RuleJudgeMode::BLOCK, gone: true));

        self::assertSame(GuardrailVerdict::ALLOW, $result->verdict);
        self::assertSame([], $this->judgedBy);
    }

    #[Test]
    public function theStricterModeDecidesWhetherAStreamIsHeldOrAnnotated(): void
    {
        $guardrail = $this->guardrail();

        self::assertTrue($guardrail->holdsStream($this->call(RuleJudgeMode::REPORT, RuleJudgeMode::BLOCK)));
        self::assertFalse($guardrail->annotatesStream($this->call(RuleJudgeMode::REPORT, RuleJudgeMode::BLOCK)));
        self::assertTrue($guardrail->annotatesStream($this->call(RuleJudgeMode::OFF, RuleJudgeMode::REPORT)));
        self::assertFalse($guardrail->holdsStream($this->call(RuleJudgeMode::OFF, RuleJudgeMode::OFF)));
    }

    #[Test]
    public function withoutANamedConfigurationOnlyTheOwnJudgeCounts(): void
    {
        $result = $this->guardrail()->checkOutputInContext($this->response(), $this->call(RuleJudgeMode::OFF, RuleJudgeMode::BLOCK, named: false));

        self::assertSame(GuardrailVerdict::ALLOW, $result->verdict);
        self::assertSame([], $this->judgedBy);
    }

    private function guardrail(int $failing = 0): RuleSetJudgeGuardrail
    {
        $ruleSets = self::createStub(RuleSetResolver::class);
        $ruleSets->method('forTags')->willReturnCallback(
            static fn (array $tags): RuleSet => RuleSet::fromSnippets([['name' => 'Ton', 'text' => 'Sprich die Leser mit „Sie“ an. (' . (in_array('site', $tags, true) ? 'site' : 'chat') . ')', 'dataClass' => null]]),
        );

        $judge = self::createStub(RuleJudge::class);
        $judge->method('judge')->willReturnCallback(function (string $text, RuleSet $rules, LlmConfiguration $judging) use ($failing): array {
            $uid                = (int) $judging->getUid();
            $this->judgedBy[]   = $uid;
            $this->tagsBy[$uid] = str_contains($rules->rules[0]->text, '(site)') ? 'site' : 'chat';
            if ($uid === $failing) {
                throw new RuntimeException('no answer', 9380998162);
            }

            // The same rule and passage from both sides.
            $shared = RuleSet::fromSnippets([['name' => 'Ton', 'text' => 'Sprich die Leser mit „Sie“ an.', 'dataClass' => null]]);

            return [new JudgeViolation($shared->rules[0], 'Hallo du')];
        });

        $named = $this->configuration(self::NAMED, self::NAMED_JUDGE, 'chat');
        $byUid = [
            self::OWN_JUDGE   => $this->configuration(self::OWN_JUDGE, 0, ''),
            self::NAMED_JUDGE => $this->configuration(self::NAMED_JUDGE, 0, ''),
            self::NAMED       => $named,
        ];
        $configurations = self::createStub(LlmConfigurationRepository::class);
        $configurations->method('findByUid')->willReturnCallback(function (int $uid) use ($byUid, $named): ?LlmConfiguration {
            if ($uid === self::NAMED) {
                $named->setIsActive($this->namedActive);
                $named->setRuleJudgeMode($this->namedMode->value);
            }

            return $byUid[$uid] ?? null;
        });

        $language = self::createStub(LanguageService::class);
        $language->method('sL')->willReturnCallback(static fn (string $key): string => match (true) {
            str_ends_with($key, 'ruleJudge.hint.violation')      => 'rule "%1$s" in "%2$s": "%3$s"',
            str_ends_with($key, 'ruleJudge.hint.unavailable')    => 'not checked',
            str_ends_with($key, 'ruleJudge.blocked.violation')   => 'blocked: %s',
            str_ends_with($key, 'ruleJudge.blocked.rule')        => '%1$s in %2$s: %3$s',
            str_ends_with($key, 'ruleJudge.blocked.write')       => 'write blocked: %s',
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
            self::createStub(ProviderRepository::class),
            self::createStub(TermListResolverInterface::class),
            new JudgeReentry(),
            new RecordingLogger(),
        );
    }

    /**
     * A call on the site's configuration that names the chat's. The named
     * configuration's mode is read when the guardrail looks it up.
     */
    private function call(RuleJudgeMode $own, RuleJudgeMode $namedMode, bool $named = true, bool $gone = false): ProviderCallContext
    {
        $configuration = $this->configuration(self::OWN, self::OWN_JUDGE, 'site', $this->ownZone);
        $configuration->setRuleJudgeMode($own->value);

        $this->namedMode = $namedMode;

        return new ProviderCallContext(
            ProviderOperation::Chat,
            'c',
            $configuration,
            metadata: $named ? [ProviderCallContext::METADATA_GUARDRAIL_CONFIGURATIONS => (string) ($gone ? 99 : self::NAMED)] : [],
        );
    }

    private function configuration(int $uid, int $judging, string $tags, TrustZone $zone = TrustZone::EXTERNAL_GLOBAL): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum($zone);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->_setProperty('uid', $uid);
        $configuration->setLlmModel($model);
        $configuration->setIsActive(true);
        if ($judging > 0) {
            $configuration->setRuleJudgeTags($tags);
            $configuration->setRuleJudgeConfiguration($judging);
        }

        return $configuration;
    }

    private function response(): CompletionResponse
    {
        return new CompletionResponse(self::TEXT, 'm', UsageStatistics::fromTokens(1, 1));
    }
}
