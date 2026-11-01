<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Streaming;

use Closure;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Provider\Fallback\FallbackCandidateResolver;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Guardrail\GuardrailInterface;
use Netresearch\NrLlm\Service\Guardrail\SecretRedactionGuardrail;
use Netresearch\NrLlm\Service\Streaming\StreamingDispatcher;
use Netresearch\NrLlm\Service\Streaming\StreamRedactionWindow;
use Netresearch\NrLlm\Service\Streaming\ToolRoundTextRelay;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Fixture\InMemoryTelemetryRepository;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingLogger;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingUsageTracker;
use Netresearch\NrLlm\Tests\Unit\Service\Streaming\Fixture\HoldingGuardrail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;

#[CoversClass(ToolRoundTextRelay::class)]
final class ToolRoundTextRelayTest extends AbstractUnitTestCase
{
    /** @var list<array{string, bool}> */
    private array $received = [];

    #[Test]
    public function withoutARedactorTheTextPassesAsItCame(): void
    {
        $relay = $this->relay(null);
        $relay->begin();
        $relay->push('Hal');
        $relay->push('lo');
        $relay->finish();

        self::assertSame([['Hal', false], ['lo', false]], $this->received);
    }

    #[Test]
    public function aSecretSplitAcrossPiecesNeverLeavesAndTheRestDoes(): void
    {
        $secret = 'sk-' . str_repeat('a1B2', 8);
        $relay  = $this->relay(fn (): StreamRedactionWindow => $this->window());
        $relay->begin();
        foreach (str_split('Der Schluessel ist ' . $secret . ' und damit ist alles gesagt.', 5) as $piece) {
            $relay->push($piece);
        }

        $relay->finish();

        $text = implode('', array_column($this->received, 0));
        self::assertStringNotContainsString('a1B2', $text);
        self::assertStringStartsWith('Der Schluessel ist ', $text);
        self::assertStringEndsWith(' und damit ist alles gesagt.', $text);
    }

    #[Test]
    public function aShortAnswerArrivesWhenTheRoundEnds(): void
    {
        $relay = $this->relay(fn (): StreamRedactionWindow => $this->window());
        $relay->begin();
        $relay->push('Hallo!');

        self::assertSame([], $this->received);

        $relay->finish();

        self::assertSame([['Hallo!', false]], $this->received);
    }

    #[Test]
    public function aSecondAttemptToldTheSinkToDropTheFirst(): void
    {
        $relay = $this->relay(null);
        $relay->begin();
        $relay->push('erster');
        $relay->begin();
        $relay->push('zweiter');

        self::assertSame([['erster', false], ['', true], ['zweiter', false]], $this->received);
    }

    #[Test]
    public function theFirstAttemptSendsNoRestart(): void
    {
        $this->relay(null)->begin();

        self::assertSame([], $this->received);
    }

    #[Test]
    public function theTimeToTheFirstTextIsMeasured(): void
    {
        $relay = $this->relay(null);
        self::assertNull($relay->firstTextMs());

        $relay->begin();
        self::assertNull($relay->firstTextMs());

        $relay->push('a');
        $first = $relay->firstTextMs();
        self::assertNotNull($first);

        $relay->push('b');
        self::assertSame($first, $relay->firstTextMs());
    }

    #[Test]
    public function aSecondAttemptMeasuresItsOwnFirstText(): void
    {
        $relay = $this->relay(null);
        $relay->begin();
        $relay->push('a');
        $relay->begin();

        self::assertNull($relay->firstTextMs());
    }

    #[Test]
    public function aHoldingGuardrailLeavesNoRelay(): void
    {
        self::assertNull($this->dispatcher(new HoldingGuardrail(GuardrailResult::allow()))->toolTextRelay(
            $this->context(),
            $this->configuration(),
            static function (string $text, bool $restart): void {},
        ));
    }

    #[Test]
    public function aGuardrailThatDoesNotHoldLeavesTheRelay(): void
    {
        self::assertInstanceOf(ToolRoundTextRelay::class, $this->dispatcher(new HoldingGuardrail(GuardrailResult::allow(), holds: false))->toolTextRelay(
            $this->context(),
            $this->configuration(),
            static function (string $text, bool $restart): void {},
        ));
    }

    #[Test]
    public function theDispatchersRelayMasksSecretsAndShowsTextEarly(): void
    {
        $relay = $this->dispatcher(new SecretRedactionGuardrail())->toolTextRelay(
            $this->context(),
            $this->configuration(),
            function (string $text, bool $restart): void {
                $this->received[] = [$text, $restart];
            },
        );
        self::assertInstanceOf(ToolRoundTextRelay::class, $relay);

        $relay->begin();
        $relay->push(str_repeat('Ein ganz normaler Satz. ', 9));
        self::assertNotSame([], $this->received, 'A text past the holdback must not wait for the default block.');

        $relay->push(' sk-' . str_repeat('a1B2', 8));
        $relay->finish();

        self::assertStringNotContainsString('a1B2', implode('', array_column($this->received, 0)));
    }

    /**
     * @param (Closure(): StreamRedactionWindow)|null $windows
     */
    private function relay(?Closure $windows): ToolRoundTextRelay
    {
        return new ToolRoundTextRelay(function (string $text, bool $restart): void {
            $this->received[] = [$text, $restart];
        }, $windows);
    }

    private function window(): StreamRedactionWindow
    {
        return new StreamRedactionWindow(
            static fn (string $s): string => (string) preg_replace('/sk-[A-Za-z0-9]{15,}/', '[REDACTED]', $s),
            32,
        );
    }

    private function context(): ProviderCallContext
    {
        return new ProviderCallContext(ProviderOperation::Tools, 'relay');
    }

    private function configuration(): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('relay');

        return $configuration;
    }

    private function dispatcher(GuardrailInterface $guardrail): StreamingDispatcher
    {
        return new StreamingDispatcher(
            self::createStub(BudgetServiceInterface::class),
            new RecordingUsageTracker(),
            new InMemoryTelemetryRepository(),
            new FallbackCandidateResolver(self::createStub(LlmConfigurationRepository::class)),
            new RecordingLogger(),
            new Context(),
            self::createStub(ExtensionConfiguration::class),
            guardrails: [$guardrail],
        );
    }
}
