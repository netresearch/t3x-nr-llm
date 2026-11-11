<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\A2a\Transport;

use GuzzleHttp\Psr7\Response;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aTransportOutcome;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aUrlGuardFactory;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aVaultTransport;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use ReflectionClass;

/**
 * The body read loop of the transport: a peer that keeps the connection open
 * and sends nothing must not keep the request alive past its timeout.
 */
#[CoversClass(A2aVaultTransport::class)]
final class A2aTransportReadTest extends TestCase
{
    #[Test]
    public function aStreamingBodyThatSendsNothingEndsAtTheDeadlineAsMaybeSent(): void
    {
        $body    = $this->silentBody();
        $started = microtime(true);
        $result  = $this->transport()->read(new Response(200, [], $body), 1024, true, $started + 0.2);
        $elapsed = microtime(true) - $started;

        self::assertSame(A2aTransportOutcome::MAYBE_SENT, $result->outcome);
        self::assertSame('TIMEOUT_AFTER_SEND', $result->reason);
        self::assertLessThan(2.0, $elapsed, 'the loop ignored its deadline');
        self::assertTrue($body->closed, 'the transfer was left open');
    }

    #[Test]
    public function aBufferedBodyThatReadsEmptyIsNotPolled(): void
    {
        $body   = $this->silentBody();
        $result = $this->transport()->read(new Response(200, [], $body), 1024, false, microtime(true) + 60);

        self::assertSame(A2aTransportOutcome::RESPONSE, $result->outcome);
        self::assertSame('', $result->body);
        self::assertSame(1, $body->reads);
    }

    private function silentBody(): A2aSilentBody
    {
        return new A2aSilentBody();
    }

    private function transport(): A2aVaultTransport
    {
        return new A2aVaultTransport(
            (new ReflectionClass(A2aUrlGuardFactory::class))->newInstanceWithoutConstructor(),
            self::createStub(VaultServiceInterface::class),
            self::createStub(RequestFactoryInterface::class),
            self::createStub(StreamFactoryInterface::class),
        );
    }
}
