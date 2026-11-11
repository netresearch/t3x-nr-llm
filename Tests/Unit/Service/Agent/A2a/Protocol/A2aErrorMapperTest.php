<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\A2a\Protocol;

use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aInvalidAgentResponseException;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aErrorCode;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aErrorMapper;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aProtocol;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aRemoteError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Error mapping (A2A-P6) and the array shape of `error.data` (A2A-D6).
 */
#[CoversClass(A2aErrorMapper::class)]
#[CoversClass(A2aRemoteError::class)]
#[CoversClass(A2aErrorCode::class)]
final class A2aErrorMapperTest extends TestCase
{
    /**
     * @return iterable<string, array{string, A2aErrorCode, string}>
     */
    public static function recordedErrors(): iterable
    {
        yield '-32001 from a2a-python' => ['error-task-not-found', A2aErrorCode::TASK_NOT_FOUND, 'TASK_NOT_FOUND'];
        yield '-32002 from a2a-python' => ['error-task-not-cancelable', A2aErrorCode::TASK_NOT_CANCELABLE, 'TASK_NOT_CANCELABLE'];
        yield '-32009 from a2a-python' => ['error-version-not-supported', A2aErrorCode::VERSION_NOT_SUPPORTED, 'VERSION_NOT_SUPPORTED'];
    }

    #[Test]
    #[DataProvider('recordedErrors')]
    public function aRecordedErrorKeepsCodeReasonAndDomain(string $fixture, A2aErrorCode $code, string $reason): void
    {
        $body  = WireFixture::load($fixture)->decodedBody();
        $error = A2aErrorMapper::fromJsonRpcError($body['error'] ?? null);

        self::assertSame($code, $error->errorCode());
        self::assertSame($reason, $error->reason);
        self::assertSame(A2aProtocol::ERROR_DOMAIN, $error->domain);
        self::assertCount(1, $error->details);
        self::assertSame(A2aProtocol::ERROR_INFO_TYPE, $error->details[0]['@type']);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function a2aCodes(): iterable
    {
        yield '-32001' => [-32001, 'TASK_NOT_FOUND'];
        yield '-32002' => [-32002, 'TASK_NOT_CANCELABLE'];
        yield '-32003' => [-32003, 'PUSH_NOTIFICATION_NOT_SUPPORTED'];
        yield '-32004' => [-32004, 'UNSUPPORTED_OPERATION'];
        yield '-32005' => [-32005, 'CONTENT_TYPE_NOT_SUPPORTED'];
        yield '-32006' => [-32006, 'INVALID_AGENT_RESPONSE'];
        yield '-32007' => [-32007, 'EXTENDED_AGENT_CARD_NOT_CONFIGURED'];
        yield '-32008' => [-32008, 'EXTENSION_SUPPORT_REQUIRED'];
        yield '-32009' => [-32009, 'VERSION_NOT_SUPPORTED'];
    }

    #[Test]
    #[DataProvider('a2aCodes')]
    public function everyA2aCodeIsNamedAndFallsBackToItsProtocolReason(int $code, string $reason): void
    {
        $error = A2aErrorMapper::fromJsonRpcError(['code' => $code, 'message' => 'x']);

        self::assertNotNull($error->errorCode());
        self::assertSame('', $error->reason, 'no ErrorInfo was sent');
        self::assertSame($reason, $error->effectiveReason());
        self::assertTrue(A2aErrorCode::isA2aRange($code));
    }

    #[Test]
    public function anErrorInfoReasonWinsOverTheProtocolDefault(): void
    {
        $error = A2aErrorMapper::fromJsonRpcError([
            'code'    => -32004,
            'message' => 'not retainable',
            'data'    => [
                ['@type' => 'type.googleapis.com/google.rpc.BadRequest', 'fieldViolations' => []],
                [
                    '@type'    => A2aProtocol::ERROR_INFO_TYPE,
                    'reason'   => 'RESULT_NOT_RETAINABLE',
                    'domain'   => A2aProtocol::NRLLM_ERROR_DOMAIN,
                    'metadata' => ['taskId' => 't-1', 'ignored' => 5],
                ],
            ],
        ]);

        self::assertSame('RESULT_NOT_RETAINABLE', $error->effectiveReason());
        self::assertSame(A2aProtocol::NRLLM_ERROR_DOMAIN, $error->domain);
        self::assertSame(['taskId' => 't-1'], $error->errorInfoMetadata);
        self::assertCount(2, $error->details, 'every typed detail is kept, not only the ErrorInfo');
    }

    #[Test]
    public function aCodeOfALaterVersionIsKeptAsANumber(): void
    {
        // v1.1.0-rc already assigns -32010 (A2A-P6).
        $error = A2aErrorMapper::fromJsonRpcError(['code' => -32010, 'message' => 'later']);

        self::assertSame(-32010, $error->code);
        self::assertNull($error->errorCode());
        self::assertSame('', $error->effectiveReason());
        self::assertTrue(A2aErrorCode::isA2aRange(-32010));
    }

    #[Test]
    public function aDataObjectInsteadOfAnArrayContributesNoDetails(): void
    {
        // A2A-D6: error.data is an array of @type objects, never an object.
        $error = A2aErrorMapper::fromJsonRpcError([
            'code'    => -32001,
            'message' => 'Task not found',
            'data'    => ['@type' => A2aProtocol::ERROR_INFO_TYPE, 'reason' => 'TASK_NOT_FOUND'],
        ]);

        self::assertSame([], $error->details);
        self::assertSame('', $error->reason);
        self::assertSame('TASK_NOT_FOUND', $error->effectiveReason());
    }

    #[Test]
    public function aDataObjectOfTypedObjectsIsNotReadAsAnArrayEither(): void
    {
        // Keyed rather than listed: still an object, still not the shape of §9.5.
        $error = A2aErrorMapper::fromJsonRpcError([
            'code'    => -32001,
            'message' => 'Task not found',
            'data'    => ['info' => ['@type' => A2aProtocol::ERROR_INFO_TYPE, 'reason' => 'SOMETHING_ELSE']],
        ]);

        self::assertSame([], $error->details);
        self::assertSame('TASK_NOT_FOUND', $error->effectiveReason());
    }

    #[Test]
    public function entriesWithoutATypeAreNotDetails(): void
    {
        $error = A2aErrorMapper::fromJsonRpcError([
            'code'    => -32603,
            'message' => 'Internal error',
            'data'    => [['reason' => 'X'], 'text', 3],
        ]);

        self::assertSame([], $error->details);
        self::assertNull($error->errorCode()?->reason());
    }

    #[Test]
    public function anErrorThatIsNotAnObjectIsInvalid(): void
    {
        $this->expectException(A2aInvalidAgentResponseException::class);

        A2aErrorMapper::fromJsonRpcError('boom');
    }
}
