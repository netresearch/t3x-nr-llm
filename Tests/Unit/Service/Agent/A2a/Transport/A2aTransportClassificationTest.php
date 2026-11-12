<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\A2a\Transport;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aTransportOutcome;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aTransportResult;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aUrlGuardFactory;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aVaultTransport;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Which transfer failures sent nothing and which may have reached the remote
 * (spec 051 A2A-C6). Getting this wrong in one direction resends a message
 * the remote already has; in the other it calls a refusal an unknown outcome.
 */
#[CoversClass(A2aVaultTransport::class)]
#[CoversClass(A2aTransportResult::class)]
final class A2aTransportClassificationTest extends TestCase
{
    /**
     * @return iterable<string, array{string, A2aTransportOutcome, string}>
     */
    public static function failures(): iterable
    {
        $tail = ' (see https://curl.se/libcurl/c/libcurl-errors.html) for https://agent.example/rpc';

        yield 'name does not resolve' => ['cURL error 6: Could not resolve host: agent.example' . $tail, A2aTransportOutcome::NOT_SENT, 'CONNECT_FAILED'];
        yield 'connection refused' => ['cURL error 7: Failed to connect to agent.example port 443' . $tail, A2aTransportOutcome::NOT_SENT, 'CONNECT_FAILED'];
        yield 'TLS handshake failed' => ['cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL' . $tail, A2aTransportOutcome::NOT_SENT, 'CONNECT_FAILED'];
        yield 'certificate refused' => ['cURL error 60: SSL certificate problem: self-signed certificate' . $tail, A2aTransportOutcome::NOT_SENT, 'CONNECT_FAILED'];
        yield 'timeout while connecting' => ['cURL error 28: Connection timed out after 5001 milliseconds' . $tail, A2aTransportOutcome::NOT_SENT, 'CONNECT_TIMEOUT'];
        yield 'timeout while resolving' => ['cURL error 28: Resolving timed out after 5000 milliseconds' . $tail, A2aTransportOutcome::NOT_SENT, 'CONNECT_TIMEOUT'];
        yield 'timeout while connecting, curl 8' => ['cURL error 28: Failed to connect to agent.example port 443 after 10002 ms: Timeout was reached' . $tail, A2aTransportOutcome::NOT_SENT, 'CONNECT_TIMEOUT'];
        yield 'timeout after the request went out' => ['cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received' . $tail, A2aTransportOutcome::MAYBE_SENT, 'TIMEOUT_AFTER_SEND'];
        yield 'connection reset' => ['cURL error 56: Recv failure: Connection reset by peer' . $tail, A2aTransportOutcome::MAYBE_SENT, 'CONNECTION_LOST'];
        yield 'empty reply' => ['cURL error 52: Empty reply from server' . $tail, A2aTransportOutcome::MAYBE_SENT, 'CONNECTION_LOST'];
        yield 'send error' => ['cURL error 55: Failed sending data to the peer' . $tail, A2aTransportOutcome::MAYBE_SENT, 'CONNECTION_LOST'];
        yield 'no curl code at all' => ['Something went wrong', A2aTransportOutcome::MAYBE_SENT, 'CONNECTION_LOST'];
    }

    #[Test]
    #[DataProvider('failures')]
    public function aFailureIsNotSentOnlyWhenNoByteCanHaveLeft(string $message, A2aTransportOutcome $expected, string $reason): void
    {
        $result = $this->transport()->classify(new RuntimeException($message));

        self::assertSame($expected, $result->outcome);
        self::assertSame($reason, $result->reason);
    }

    #[Test]
    public function aConnectExceptionWithoutACurlCodeSentNothing(): void
    {
        $result = $this->transport()->classify(new ConnectException('Connection refused', new Request('POST', 'https://agent.example/rpc')));

        self::assertSame(A2aTransportOutcome::NOT_SENT, $result->outcome);
    }

    #[Test]
    public function aConnectExceptionWithAPostSendCodeMayHaveBeenSent(): void
    {
        // Guzzle 7 raised ConnectException for a timeout without a response,
        // whether or not the request had left; the curl code decides.
        $result = $this->transport()->classify(new ConnectException(
            'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received',
            new Request('POST', 'https://agent.example/rpc'),
        ));

        self::assertSame(A2aTransportOutcome::MAYBE_SENT, $result->outcome);
    }

    #[Test]
    public function aSecretInTheMessageIsNotKept(): void
    {
        $result = $this->transport()->classify(new RuntimeException('cURL error 7: Failed to connect to https://agent.example/rpc?token=s3cr3t'));

        self::assertStringNotContainsString('s3cr3t', $result->detail);
    }

    #[Test]
    public function theMediaTypeDropsParametersAndCase(): void
    {
        self::assertSame('application/json', A2aTransportResult::response(200, 'Application/JSON; charset=utf-8', '', '{}', false)->mediaType());
        self::assertSame('', A2aTransportResult::response(200, '', '', '{}', false)->mediaType());
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
