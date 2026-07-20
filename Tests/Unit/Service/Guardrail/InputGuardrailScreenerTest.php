<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Guardrail;

use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Exception\GuardrailApprovalRequiredException;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Service\Guardrail\InputGuardrailInterface;
use Netresearch\NrLlm\Service\Guardrail\InputGuardrailScreener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InputGuardrailScreener::class)]
final class InputGuardrailScreenerTest extends TestCase
{
    #[Test]
    public function passesMessagesThroughUnchangedWhenEveryGuardrailAllows(): void
    {
        $screener = new InputGuardrailScreener([$this->allowing()]);
        $messages = [ChatMessage::user('hello'), ['role' => 'system', 'content' => 'be nice']];

        self::assertSame($messages, $screener->screen($messages));
    }

    #[Test]
    public function passesMessagesThroughUnchangedWhenNoGuardrailsAreRegistered(): void
    {
        $screener = new InputGuardrailScreener([]);
        $messages = [ChatMessage::user('hello')];

        self::assertSame($messages, $screener->screen($messages));
    }

    #[Test]
    public function redactsAChatMessageContentInPlacePreservingRole(): void
    {
        $screener = new InputGuardrailScreener([$this->redacting('secret', '***')]);

        $result = $screener->screen([ChatMessage::user('my secret here')]);

        self::assertCount(1, $result);
        $message = $result[0];
        self::assertInstanceOf(ChatMessage::class, $message);
        self::assertSame('my *** here', $message->content);
        self::assertSame('user', $message->role);
    }

    #[Test]
    public function redactsALegacyArrayMessageContentPreservingOtherKeys(): void
    {
        $screener = new InputGuardrailScreener([$this->redacting('secret', '***')]);

        $result = $screener->screen([['role' => 'user', 'content' => 'a secret value', 'name' => 'kept']]);

        $message = $result[0];
        self::assertIsArray($message);
        self::assertSame('a *** value', $message['content']);
        self::assertSame('kept', $message['name']);
    }

    #[Test]
    public function passesAMessageWithNoStringContentThrough(): void
    {
        $screener = new InputGuardrailScreener([$this->redacting('x', 'y')]);
        // An assistant tool-call turn carries empty content — nothing to screen.
        $message = ChatMessage::assistantToolCalls([new ToolCall('call_1', 'do_it', [])]);

        $result = $screener->screen([$message]);

        self::assertSame($message, $result[0]);
    }

    #[Test]
    public function appliesEveryGuardrailInOrder(): void
    {
        $screener = new InputGuardrailScreener([
            $this->redacting('one', '1'),
            $this->redacting('two', '2'),
        ]);

        $result = $screener->screen([ChatMessage::user('one and two')]);

        $message = $result[0];
        self::assertInstanceOf(ChatMessage::class, $message);
        self::assertSame('1 and 2', $message->content);
    }

    #[Test]
    public function throwsWhenAGuardrailDeniesThePrompt(): void
    {
        $screener = new InputGuardrailScreener([$this->denying('policy says no')]);

        $this->expectException(GuardrailViolationException::class);
        $this->expectExceptionMessage('policy says no');
        $screener->screen([ChatMessage::user('anything')]);
    }

    #[Test]
    public function throwsApprovalRequiredWhenAGuardrailFlagsThePrompt(): void
    {
        $screener = new InputGuardrailScreener([$this->requireApproval('needs review')]);

        $this->expectException(GuardrailApprovalRequiredException::class);
        $this->expectExceptionMessage('needs review');
        $screener->screen([ChatMessage::user('anything')]);
    }

    #[Test]
    public function ignoresARetryVerdictOnTheInputSide(): void
    {
        $screener = new InputGuardrailScreener([$this->retrying()]);
        $messages = [ChatMessage::user('hello')];

        // RETRY re-asks the provider — meaningless before a call, so it is a pass.
        self::assertSame($messages, $screener->screen($messages));
    }

    #[Test]
    public function screenTextReturnsAnEmptyStringUnchanged(): void
    {
        $screener = new InputGuardrailScreener([$this->redacting('x', 'y')]);

        self::assertSame('', $screener->screenText(''));
    }

    #[Test]
    public function screenTextPassesTextThroughWhenEveryGuardrailAllows(): void
    {
        $screener = new InputGuardrailScreener([$this->allowing()]);

        self::assertSame('a clean prompt', $screener->screenText('a clean prompt'));
    }

    #[Test]
    public function screenTextRedactsAndAppliesEveryGuardrailInOrder(): void
    {
        $screener = new InputGuardrailScreener([
            $this->redacting('one', '1'),
            $this->redacting('two', '2'),
        ]);

        self::assertSame('1 and 2', $screener->screenText('one and two'));
    }

    #[Test]
    public function screenTextThrowsWhenAGuardrailDeniesThePrompt(): void
    {
        $screener = new InputGuardrailScreener([$this->denying('policy says no')]);

        $this->expectException(GuardrailViolationException::class);
        $this->expectExceptionMessage('policy says no');
        $screener->screenText('anything');
    }

    #[Test]
    public function screenTextThrowsApprovalRequiredWhenAGuardrailFlagsThePrompt(): void
    {
        $screener = new InputGuardrailScreener([$this->requireApproval('needs review')]);

        $this->expectException(GuardrailApprovalRequiredException::class);
        $this->expectExceptionMessage('needs review');
        $screener->screenText('anything');
    }

    private function allowing(): InputGuardrailInterface
    {
        return new class implements InputGuardrailInterface {
            public function checkInput(string $text): GuardrailResult
            {
                return GuardrailResult::allow();
            }
        };
    }

    private function redacting(string $needle, string $replacement): InputGuardrailInterface
    {
        return new class ($needle, $replacement) implements InputGuardrailInterface {
            public function __construct(private readonly string $needle, private readonly string $replacement) {}

            public function checkInput(string $text): GuardrailResult
            {
                if (!str_contains($text, $this->needle)) {
                    return GuardrailResult::allow();
                }

                return GuardrailResult::redact(str_replace($this->needle, $this->replacement, $text), 'redacted');
            }
        };
    }

    private function denying(string $reason): InputGuardrailInterface
    {
        return new class ($reason) implements InputGuardrailInterface {
            public function __construct(private readonly string $reason) {}

            public function checkInput(string $text): GuardrailResult
            {
                return GuardrailResult::deny($this->reason);
            }
        };
    }

    private function requireApproval(string $reason): InputGuardrailInterface
    {
        return new class ($reason) implements InputGuardrailInterface {
            public function __construct(private readonly string $reason) {}

            public function checkInput(string $text): GuardrailResult
            {
                return GuardrailResult::requireApproval($this->reason);
            }
        };
    }

    private function retrying(): InputGuardrailInterface
    {
        return new class implements InputGuardrailInterface {
            public function checkInput(string $text): GuardrailResult
            {
                return GuardrailResult::retry('later');
            }
        };
    }
}
