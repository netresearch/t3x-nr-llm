<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Guardrail\Term;

use Netresearch\NrLlm\Domain\Enum\GuardrailVerdict;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Provider\Middleware\GuardrailMiddleware;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListGuardrail;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListMessages;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListResolverInterface;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListRule;
use Netresearch\NrLlm\Service\Guardrail\Term\TermMatcher;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * A caller that names further configurations gets their term lists on top of
 * the lists of the configuration the call runs on (ADR-290): a site's own
 * configuration replaces the chat's, and the chat's bans stay.
 */
#[CoversClass(TermListGuardrail::class)]
final class TermListGuardrailConfigurationsTest extends TestCase
{
    private const RUNS_ON = 7;

    private const NAMED = 3;

    /** @var list<int> */
    private array $asked = [];

    #[Test]
    public function aBanOfTheNamedConfigurationBlocks(): void
    {
        $guardrail = $this->guardrail([self::RUNS_ON => [], self::NAMED => [$this->rule('Chat', ['Rabatt'])]]);

        $result = $guardrail->checkOutputInContext($this->write('Großer Rabatt'), $this->chatContext('3'));

        self::assertSame(GuardrailVerdict::DENY, $result->verdict);
        self::assertSame('write|Chat|Rabatt', $result->reason);
    }

    #[Test]
    public function aBanOfTheConfigurationTheCallRunsOnStillBlocks(): void
    {
        $guardrail = $this->guardrail([self::RUNS_ON => [$this->rule('Site', ['Rabatt'])], self::NAMED => []]);

        $result = $guardrail->checkOutputInContext($this->write('Großer Rabatt'), $this->chatContext('3'));

        self::assertSame(GuardrailVerdict::DENY, $result->verdict);
        self::assertSame('write|Site|Rabatt', $result->reason);
    }

    /**
     * A list naming both configurations gives one message with its terms once.
     */
    #[Test]
    public function aListNamingBothConfigurationsGivesOneMessage(): void
    {
        $shared    = $this->rule('Marke', ['Rabatt', 'Schnäppchen']);
        $guardrail = $this->guardrail([self::RUNS_ON => [$shared], self::NAMED => [$shared]]);

        $result = $guardrail->checkOutputInContext($this->write('Rabatt und Schnäppchen'), $this->chatContext('3'));

        self::assertSame('write|Marke|Rabatt, Schnäppchen', $result->reason);
    }

    /**
     * Two lists banning the same term: the first one blocks, with one message.
     */
    #[Test]
    public function twoListsBanningTheSameTermGiveOneMessage(): void
    {
        $guardrail = $this->guardrail([self::RUNS_ON => [$this->rule('Site', ['Rabatt'])], self::NAMED => [$this->rule('Chat', ['Rabatt'])]]);

        $result = $guardrail->checkOutputInContext($this->write('Großer Rabatt'), $this->chatContext('3'));

        self::assertSame('write|Site|Rabatt', $result->reason);
    }

    #[Test]
    public function withoutANamedConfigurationOnlyTheOwnListsAreAsked(): void
    {
        $guardrail = $this->guardrail([self::RUNS_ON => [], self::NAMED => [$this->rule('Chat', ['Rabatt'])]]);

        $result = $guardrail->checkOutputInContext($this->write('Großer Rabatt'), $this->chatContext(null));

        self::assertSame(GuardrailVerdict::ALLOW, $result->verdict);
        self::assertSame([self::RUNS_ON], $this->asked);
    }

    /**
     * Its own uid, zero, garbage and repeats ask nothing twice.
     */
    #[Test]
    public function theNamedConfigurationsAreReadOnceEach(): void
    {
        $guardrail = $this->guardrail([self::RUNS_ON => [], self::NAMED => []]);

        $guardrail->checkOutputInContext($this->write('Sommer'), $this->chatContext('3, 7,0,x,3'));

        self::assertSame([self::RUNS_ON, self::NAMED], $this->asked);
    }

    /**
     * Without a list of its own a call is held when a named configuration has one.
     */
    #[Test]
    public function aListOfTheNamedConfigurationHoldsTheStream(): void
    {
        $guardrail = $this->guardrail([self::RUNS_ON => [], self::NAMED => [$this->rule('Chat', ['Rabatt'])]]);
        $context   = $this->context([ProviderCallContext::METADATA_GUARDRAIL_CONFIGURATIONS => '3']);

        self::assertTrue($guardrail->holdsStream($context));
        self::assertSame(GuardrailVerdict::DENY, $guardrail->checkOutputInContext($this->response('Ein Rabatt'), $context)->verdict);
    }

    /**
     * @param array<int, list<TermListRule>> $lists by configuration uid
     */
    private function guardrail(array $lists): TermListGuardrail
    {
        $resolver = self::createStub(TermListResolverInterface::class);
        $resolver->method('forConfiguration')->willReturnCallback(function (int $uid) use ($lists): array {
            $this->asked[] = $uid;

            return $lists[$uid] ?? [];
        });

        $language = self::createStub(LanguageService::class);
        $language->method('sL')->willReturnCallback(static fn (string $key): string => match (true) {
            str_ends_with($key, '.forbidden')      => 'forbidden|%1$s|%2$s',
            str_ends_with($key, '.forbiddenWrite') => 'write|%1$s|%2$s',
            default                                => 'other',
        });
        $factory = self::createStub(LanguageServiceFactory::class);
        $factory->method('createFromUserPreferences')->willReturn($language);

        return new TermListGuardrail($resolver, new TermMatcher(), new TermListMessages($factory), new RecordingLogger());
    }

    /**
     * @param list<string> $forbidden
     */
    private function rule(string $name, array $forbidden): TermListRule
    {
        return new TermListRule($name, '', '', $forbidden, [], '');
    }

    private function write(string $header): CompletionResponse
    {
        return $this->response('', [ToolCall::function('1', 'update_content', ['fields' => ['header' => $header]])]);
    }

    /**
     * @param list<ToolCall> $toolCalls
     */
    private function response(string $content, array $toolCalls = []): CompletionResponse
    {
        return new CompletionResponse($content, 'm', UsageStatistics::fromTokens(1, 1), toolCalls: $toolCalls === [] ? null : $toolCalls);
    }

    private function chatContext(?string $named): ProviderCallContext
    {
        $metadata = [
            GuardrailMiddleware::METADATA_AGENT_RUN_UID  => 5,
            GuardrailMiddleware::METADATA_RUN_READ_TOOLS => ['search_records'],
        ];
        if ($named !== null) {
            $metadata[ProviderCallContext::METADATA_GUARDRAIL_CONFIGURATIONS] = $named;
        }

        return $this->context($metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function context(array $metadata): ProviderCallContext
    {
        $configuration = new LlmConfiguration();
        $configuration->_setProperty('uid', self::RUNS_ON);

        return new ProviderCallContext(ProviderOperation::Chat, 'c', $configuration, metadata: $metadata);
    }
}
