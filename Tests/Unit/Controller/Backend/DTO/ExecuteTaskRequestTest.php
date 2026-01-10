<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Controller\Backend\DTO;

use Netresearch\NrLlm\Controller\Backend\DTO\ExecuteTaskRequest;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(ExecuteTaskRequest::class)]
class ExecuteTaskRequestTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $request = new ExecuteTaskRequest(uid: 123, input: 'test input');

        self::assertEquals(123, $request->uid);
        self::assertEquals('test input', $request->input);
    }

    #[Test]
    public function fromRequestExtractsValidData(): void
    {
        $serverRequest = $this->createServerRequestMock([
            'uid' => 42,
            'input' => 'Execute this task',
        ]);

        $request = ExecuteTaskRequest::fromRequest($serverRequest);

        self::assertEquals(42, $request->uid);
        self::assertEquals('Execute this task', $request->input);
    }

    #[Test]
    public function fromRequestHandlesStringNumericUid(): void
    {
        $serverRequest = $this->createServerRequestMock([
            'uid' => '123',
            'input' => 'test',
        ]);

        $request = ExecuteTaskRequest::fromRequest($serverRequest);

        self::assertEquals(123, $request->uid);
    }

    #[Test]
    public function fromRequestUsesDefaultsForMissingData(): void
    {
        $serverRequest = $this->createServerRequestMock([]);

        $request = ExecuteTaskRequest::fromRequest($serverRequest);

        self::assertEquals(0, $request->uid);
        self::assertEquals('', $request->input);
    }

    #[Test]
    public function fromRequestHandlesNullBody(): void
    {
        $serverRequest = self::createStub(ServerRequestInterface::class);
        $serverRequest->method('getParsedBody')->willReturn(null);

        $request = ExecuteTaskRequest::fromRequest($serverRequest);

        self::assertEquals(0, $request->uid);
        self::assertEquals('', $request->input);
    }

    #[Test]
    #[DataProvider('invalidUidProvider')]
    public function fromRequestHandlesInvalidUid(mixed $uid, int $expected): void
    {
        $serverRequest = $this->createServerRequestMock([
            'uid' => $uid,
            'input' => 'test',
        ]);

        $request = ExecuteTaskRequest::fromRequest($serverRequest);

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
            'float string' => ['12.5', 12],
            'negative' => [-5, -5],
        ];
    }

    #[Test]
    #[DataProvider('invalidInputProvider')]
    public function fromRequestHandlesInvalidInput(mixed $input, string $expected): void
    {
        $serverRequest = $this->createServerRequestMock([
            'uid' => 1,
            'input' => $input,
        ]);

        $request = ExecuteTaskRequest::fromRequest($serverRequest);

        self::assertEquals($expected, $request->input);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidInputProvider(): array
    {
        return [
            'array' => [['test'], ''],
            'integer' => [123, '123'],
            'boolean true' => [true, '1'],
            'boolean false' => [false, ''],
            'null' => [null, ''],
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
