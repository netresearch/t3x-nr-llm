<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Controller\Backend\DTO;

use Netresearch\NrLlm\Controller\Backend\DTO\FetchRecordsRequest;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(FetchRecordsRequest::class)]
class FetchRecordsRequestTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $request = new FetchRecordsRequest(
            table: 'pages',
            limit: 50,
            labelField: 'title',
        );

        self::assertEquals('pages', $request->table);
        self::assertEquals(50, $request->limit);
        self::assertEquals('title', $request->labelField);
    }

    #[Test]
    public function fromRequestExtractsValidData(): void
    {
        $serverRequest = $this->createServerRequestMock([
            'table' => 'tt_content',
            'limit' => 100,
            'labelField' => 'header',
        ]);

        $request = FetchRecordsRequest::fromRequest($serverRequest);

        self::assertEquals('tt_content', $request->table);
        self::assertEquals(100, $request->limit);
        self::assertEquals('header', $request->labelField);
    }

    #[Test]
    public function fromRequestCapsLimitAtMaximum(): void
    {
        $serverRequest = $this->createServerRequestMock([
            'table' => 'pages',
            'limit' => 500,
            'labelField' => 'title',
        ]);

        $request = FetchRecordsRequest::fromRequest($serverRequest);

        // MAX_LIMIT is 200
        self::assertEquals(200, $request->limit);
    }

    #[Test]
    public function fromRequestUsesDefaultLimit(): void
    {
        $serverRequest = $this->createServerRequestMock([
            'table' => 'pages',
            'labelField' => 'title',
        ]);

        $request = FetchRecordsRequest::fromRequest($serverRequest);

        // DEFAULT_LIMIT is 50
        self::assertEquals(50, $request->limit);
    }

    #[Test]
    public function fromRequestHandlesNullBody(): void
    {
        $serverRequest = self::createStub(ServerRequestInterface::class);
        $serverRequest->method('getParsedBody')->willReturn(null);

        $request = FetchRecordsRequest::fromRequest($serverRequest);

        self::assertEquals('', $request->table);
        self::assertEquals(50, $request->limit);
        self::assertEquals('', $request->labelField);
    }

    #[Test]
    public function fromRequestHandlesStringNumericLimit(): void
    {
        $serverRequest = $this->createServerRequestMock([
            'table' => 'pages',
            'limit' => '75',
            'labelField' => 'title',
        ]);

        $request = FetchRecordsRequest::fromRequest($serverRequest);

        self::assertEquals(75, $request->limit);
    }

    #[Test]
    public function isValidReturnsTrueForValidRequest(): void
    {
        $request = new FetchRecordsRequest(
            table: 'pages',
            limit: 50,
            labelField: 'title',
        );

        self::assertTrue($request->isValid());
    }

    #[Test]
    public function isValidReturnsFalseForEmptyTable(): void
    {
        $request = new FetchRecordsRequest(
            table: '',
            limit: 50,
            labelField: 'title',
        );

        self::assertFalse($request->isValid());
    }

    #[Test]
    public function isValidReturnsTrueForEmptyLabelField(): void
    {
        $request = new FetchRecordsRequest(
            table: 'pages',
            limit: 50,
            labelField: '',
        );

        // Empty labelField is valid - table is the required field
        self::assertTrue($request->isValid());
    }

    #[Test]
    #[DataProvider('invalidLimitProvider')]
    public function fromRequestHandlesInvalidLimit(mixed $limit, int $expected): void
    {
        $serverRequest = $this->createServerRequestMock([
            'table' => 'pages',
            'limit' => $limit,
            'labelField' => 'title',
        ]);

        $request = FetchRecordsRequest::fromRequest($serverRequest);

        self::assertEquals($expected, $request->limit);
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function invalidLimitProvider(): array
    {
        return [
            'non-numeric string' => ['abc', 50],
            'array' => [['test'], 50],
            'zero' => [0, 0],
            'negative' => [-10, -10],
        ];
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
