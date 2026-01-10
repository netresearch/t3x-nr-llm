<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Controller\Backend\DTO;

use Netresearch\NrLlm\Controller\Backend\DTO\LoadRecordDataRequest;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(LoadRecordDataRequest::class)]
class LoadRecordDataRequestTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $request = new LoadRecordDataRequest(
            table: 'pages',
            uids: '1,2,3',
            uidList: [1, 2, 3],
        );

        self::assertEquals('pages', $request->table);
        self::assertEquals('1,2,3', $request->uids);
        self::assertEquals([1, 2, 3], $request->uidList);
    }

    #[Test]
    public function fromRequestExtractsValidData(): void
    {
        $serverRequest = $this->createServerRequestMock([
            'table' => 'tt_content',
            'uids' => '10,20,30',
        ]);

        $request = LoadRecordDataRequest::fromRequest($serverRequest);

        self::assertEquals('tt_content', $request->table);
        self::assertEquals('10,20,30', $request->uids);
        self::assertEquals([10, 20, 30], $request->uidList);
    }

    #[Test]
    public function fromRequestParsesCommaSeparatedUids(): void
    {
        $serverRequest = $this->createServerRequestMock([
            'table' => 'pages',
            'uids' => '1,5,10,100',
        ]);

        $request = LoadRecordDataRequest::fromRequest($serverRequest);

        self::assertEquals([1, 5, 10, 100], $request->uidList);
    }

    #[Test]
    public function fromRequestFiltersInvalidUids(): void
    {
        $serverRequest = $this->createServerRequestMock([
            'table' => 'pages',
            'uids' => '1,0,-5,10,abc,20',
        ]);

        $request = LoadRecordDataRequest::fromRequest($serverRequest);

        // Only positive integers should remain
        self::assertEquals([1, 10, 20], $request->uidList);
    }

    #[Test]
    public function fromRequestHandlesEmptyUids(): void
    {
        $serverRequest = $this->createServerRequestMock([
            'table' => 'pages',
            'uids' => '',
        ]);

        $request = LoadRecordDataRequest::fromRequest($serverRequest);

        self::assertEquals('', $request->uids);
        self::assertEquals([], $request->uidList);
    }

    #[Test]
    public function fromRequestHandlesNullBody(): void
    {
        $serverRequest = self::createStub(ServerRequestInterface::class);
        $serverRequest->method('getParsedBody')->willReturn(null);

        $request = LoadRecordDataRequest::fromRequest($serverRequest);

        self::assertEquals('', $request->table);
        self::assertEquals('', $request->uids);
        self::assertEquals([], $request->uidList);
    }

    #[Test]
    public function isValidReturnsTrueForValidRequest(): void
    {
        $request = new LoadRecordDataRequest(
            table: 'pages',
            uids: '1,2,3',
            uidList: [1, 2, 3],
        );

        self::assertTrue($request->isValid());
    }

    #[Test]
    public function isValidReturnsFalseForEmptyTable(): void
    {
        $request = new LoadRecordDataRequest(
            table: '',
            uids: '1,2,3',
            uidList: [1, 2, 3],
        );

        self::assertFalse($request->isValid());
    }

    #[Test]
    public function isValidReturnsFalseForEmptyUidList(): void
    {
        $request = new LoadRecordDataRequest(
            table: 'pages',
            uids: '',
            uidList: [],
        );

        self::assertFalse($request->isValid());
    }

    #[Test]
    public function isValidReturnsFalseForBothEmpty(): void
    {
        $request = new LoadRecordDataRequest(
            table: '',
            uids: '',
            uidList: [],
        );

        self::assertFalse($request->isValid());
    }

    /**
     * @param list<int> $expectedList
     */
    #[Test]
    #[DataProvider('uidParsingProvider')]
    public function fromRequestParsesUidsCorrectly(string $uids, array $expectedList): void
    {
        $serverRequest = $this->createServerRequestMock([
            'table' => 'pages',
            'uids' => $uids,
        ]);

        $request = LoadRecordDataRequest::fromRequest($serverRequest);

        self::assertEquals($expectedList, $request->uidList);
    }

    /**
     * @return array<string, array{string, list<int>}>
     */
    public static function uidParsingProvider(): array
    {
        return [
            'single uid' => ['42', [42]],
            'multiple uids' => ['1,2,3', [1, 2, 3]],
            'with spaces' => ['1, 2, 3', [1, 2, 3]],
            'with zeros' => ['1,0,2,0,3', [1, 2, 3]],
            'with negatives' => ['1,-2,3,-4,5', [1, 3, 5]],
            'all invalid' => ['0,-1,-2', []],
            'mixed valid invalid' => ['abc,1,def,2,ghi', [1, 2]],
            'trailing comma' => ['1,2,3,', [1, 2, 3]],
            'leading comma' => [',1,2,3', [1, 2, 3]],
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
