<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\A2a\Protocol;

use DateTimeImmutable;
use DateTimeZone;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aAgentCapabilities;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aListTasksQuery;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aMessage;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aPart;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aProtocol;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aRole;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aSendMessageConfiguration;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTaskState;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTimestamp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Value objects and serialisation rules (A2A-P2, A2A-P3, A2A-D1).
 */
#[CoversClass(A2aTimestamp::class)]
#[CoversClass(A2aTaskState::class)]
#[CoversClass(A2aSendMessageConfiguration::class)]
#[CoversClass(A2aListTasksQuery::class)]
#[CoversClass(A2aMessage::class)]
#[CoversClass(A2aPart::class)]
#[CoversClass(A2aProtocol::class)]
#[CoversClass(A2aAgentCapabilities::class)]
final class A2aValueObjectTest extends TestCase
{
    #[Test]
    public function timestampsAreEmittedInUtcWithMillisecondsAndZ(): void
    {
        $time = new DateTimeImmutable('2026-11-10 22:54:29.148533', new DateTimeZone('Europe/Berlin'));

        self::assertSame('2026-11-10T21:54:29.148Z', A2aTimestamp::format($time));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function timestamps(): iterable
    {
        yield 'milliseconds' => ['2026-11-10T21:54:29.148Z', '2026-11-10 21:54:29.148000'];
        yield 'microseconds (a2a-python)' => ['2026-11-10T21:54:29.148533Z', '2026-11-10 21:54:29.148533'];
        yield 'nanoseconds' => ['2026-11-10T21:54:29.148533999Z', '2026-11-10 21:54:29.148533'];
        yield 'whole seconds' => ['2026-11-10T21:54:29Z', '2026-11-10 21:54:29.000000'];
        yield 'an offset, even +00:00' => ['2026-11-10T21:54:29.148+00:00', null];
        yield 'lower-case z' => ['2026-11-10T21:54:29.148z', null];
        yield 'no zone' => ['2026-11-10T21:54:29.148', null];
        yield 'a date that does not exist' => ['2026-02-30T00:00:00Z', null];
        yield 'not a timestamp' => ['yesterday', null];
    }

    #[Test]
    #[DataProvider('timestamps')]
    public function onlyUtcTimestampsEndingInZAreRead(string $value, ?string $expected): void
    {
        $parsed = A2aTimestamp::parse($value);

        self::assertSame($expected, $parsed?->format('Y-m-d H:i:s.u'));
        if ($parsed instanceof DateTimeImmutable) {
            self::assertSame('+00:00', $parsed->format('P'));
        }
    }

    #[Test]
    public function exactlyFourStatesAreTerminalAndTwoInterrupted(): void
    {
        $terminal    = array_values(array_filter(A2aTaskState::cases(), static fn (A2aTaskState $s): bool => $s->isTerminal()));
        $interrupted = array_values(array_filter(A2aTaskState::cases(), static fn (A2aTaskState $s): bool => $s->isInterrupted()));

        self::assertSame([A2aTaskState::COMPLETED, A2aTaskState::FAILED, A2aTaskState::CANCELED, A2aTaskState::REJECTED], $terminal);
        self::assertSame([A2aTaskState::INPUT_REQUIRED, A2aTaskState::AUTH_REQUIRED], $interrupted);
    }

    #[Test]
    public function aDefaultConfigurationBlocksAndSendsNoReturnImmediatelyMember(): void
    {
        // A2A-D1: the proto's `return_immediately` defaults to false, which is
        // BLOCKING; the prose of §3.1.1 says the opposite. The default sends
        // nothing and so leaves the proto's default in force.
        $default = new A2aSendMessageConfiguration();

        self::assertFalse($default->returnImmediately);
        self::assertSame([], $default->toArray());
        self::assertSame(['returnImmediately' => true], A2aSendMessageConfiguration::returningImmediately()->toArray());
    }

    #[Test]
    public function anExplicitHistoryLengthOfZeroIsSent(): void
    {
        self::assertSame(['historyLength' => 0], (new A2aSendMessageConfiguration(historyLength: 0))->toArray());
    }

    #[Test]
    public function aListPageSizeOutsideOneToHundredIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $query = new A2aListTasksQuery(pageSize: 101);

        // S1848: the instantiation must be used; name what was accepted.
        self::fail('A page size of 101 was accepted: ' . json_encode($query->toArray(), JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function aMessageSentToARemoteNeedsAUserRoleAndAPart(): void
    {
        A2aMessage::userText('m', 'x')->assertSendable();

        $this->expectException(InvalidArgumentException::class);
        (new A2aMessage('m', A2aRole::AGENT, [A2aPart::text('x')]))->assertSendable();
    }

    #[Test]
    public function aMessageWithoutPartsIsNotSendable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new A2aMessage('m', A2aRole::USER, []))->assertSendable();
    }

    #[Test]
    public function aRawPartTravelsAsBase64(): void
    {
        self::assertSame(['raw' => base64_encode("\x00\xff"), 'mediaType' => 'application/octet-stream'], A2aPart::raw("\x00\xff", 'application/octet-stream')->toArray());
    }

    #[Test]
    public function withMessageIdKeepsEverythingElse(): void
    {
        $message = new A2aMessage('a', A2aRole::USER, [A2aPart::text('x')], 'ctx', 'task', ['k' => 1]);
        $renamed = $message->withMessageId('b');

        self::assertSame('b', $renamed->messageId);
        self::assertSame(array_merge($message->toArray(), ['messageId' => 'b']), $renamed->toArray());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function versions(): iterable
    {
        yield '1.0' => ['1.0', true];
        yield '1.0.1' => ['1.0.1', true];
        yield '1.0.12' => ['1.0.12', true];
        yield '0.3' => ['0.3', false];
        yield '1.1' => ['1.1', false];
        yield '10.0' => ['10.0', false];
        yield 'empty' => ['', false];
    }

    #[Test]
    #[DataProvider('versions')]
    public function onlyOnePointZeroAndItsPatchLevelsAreSpoken(string $version, bool $supported): void
    {
        self::assertSame($supported, A2aProtocol::isSupportedVersion($version));
    }

    #[Test]
    public function capabilitiesDistinguishAbsentFromFalse(): void
    {
        self::assertNotSame(
            (new A2aAgentCapabilities(false))->fingerprint(),
            (new A2aAgentCapabilities())->fingerprint(),
        );
    }
}
