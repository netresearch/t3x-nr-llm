<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Controller\Backend\DTO;

use Netresearch\NrLlm\Controller\Backend\DTO\RefreshInputRequest;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(RefreshInputRequest::class)]
class RefreshInputRequestTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorSetsUid(): void
    {
        $request = new RefreshInputRequest(uid: 123);

        self::assertEquals(123, $request->uid);
    }

    #[Test]
    public function fromRequestExtractsUid(): void
    {
        $serverRequest = $this->createServerRequestMock(['uid' => 42]);

        $request = RefreshInputRequest::fromRequest($serverRequest);

        self::assertEquals(42, $request->uid);
    }

    #[Test]
    public function fromRequestHandlesStringNumericUid(): void
    {
        $serverRequest = $this->createServerRequestMock(['uid' => '123']);

        $request = RefreshInputRequest::fromRequest($serverRequest);

        self::assertEquals(123, $request->uid);
    }

    #[Test]
    public function fromRequestUsesDefaultForMissingUid(): void
    {
        $serverRequest = $this->createServerRequestMock([]);

        $request = RefreshInputRequest::fromRequest($serverRequest);

        self::assertEquals(0, $request->uid);
    }

    #[Test]
    public function fromRequestHandlesNullBody(): void
    {
        $serverRequest = self::createStub(ServerRequestInterface::class);
        $serverRequest->method('getParsedBody')->willReturn(null);

        $request = RefreshInputRequest::fromRequest($serverRequest);

        self::assertEquals(0, $request->uid);
    }

    #[Test]
    #[DataProvider('invalidUidProvider')]
    public function fromRequestHandlesInvalidUid(mixed $uid, int $expected): void
    {
        $serverRequest = $this->createServerRequestMock(['uid' => $uid]);

        $request = RefreshInputRequest::fromRequest($serverRequest);

        self::assertEquals($expected, $request->uid);
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function invalidUidProvider(): array
    {
        return [
            'non-numeric string' => ['abc', 0],
            'array' => [['test'], 0],
            'float' => [12.5, 12],
            'float string' => ['12.5', 12],
            'negative' => [-5, -5],
            'negative string' => ['-10', -10],
            'empty string' => ['', 0],
            'boolean true' => [true, 0],
            'boolean false' => [false, 0],
            'null' => [null, 0],
        ];
    }

    #[Test]
    public function fromRequestHandlesLargeUid(): void
    {
        $serverRequest = $this->createServerRequestMock(['uid' => 2147483647]);

        $request = RefreshInputRequest::fromRequest($serverRequest);

        self::assertEquals(2147483647, $request->uid);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createServerRequestMock(array $body): ServerRequestInterface
    {
        $request = self::createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($body);
        return $request;
    }
}
