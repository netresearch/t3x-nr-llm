<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\A2a\Protocol;

use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aInvalidAgentResponseException;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aErrorCode;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aJsonRpcCodec;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aMessage;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aSendMessageConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The JSON-RPC envelope (A2A-P1, A2A-P4 id check) and the request digest of
 * the send-intent journal (A2A-C4, A2A-C5).
 */
#[CoversClass(A2aJsonRpcCodec::class)]
final class A2aJsonRpcCodecTest extends TestCase
{
    #[Test]
    public function theEnvelopeEmbedsTheEncodedParamsVerbatim(): void
    {
        $params = A2aJsonRpcCodec::encodeParams(['message' => A2aMessage::userText('m-1', 'Grüße / €')->toArray()]);
        $body   = A2aJsonRpcCodec::encodeRequest('SendMessage', $params, 'req-1');

        self::assertSame(
            '{"jsonrpc":"2.0","id":"req-1","method":"SendMessage","params":{"message":{"messageId":"m-1","role":"ROLE_USER","parts":[{"text":"Grüße / €"}]}}}',
            $body,
        );
    }

    #[Test]
    public function emptyParamsAreAnObject(): void
    {
        self::assertSame('{}', A2aJsonRpcCodec::encodeParams([]));
    }

    #[Test]
    public function twoIdenticalRequestsHaveOneDigestWhateverTheirJsonRpcIds(): void
    {
        $build = static fn (): string => A2aJsonRpcCodec::encodeParams([
            'message'       => A2aMessage::userText('m-1', 'hello')->toArray(),
            'configuration' => A2aSendMessageConfiguration::returningImmediately()->toArray(),
        ]);

        $first  = $build();
        $second = $build();

        self::assertSame(A2aJsonRpcCodec::digest($first), A2aJsonRpcCodec::digest($second));
        self::assertNotSame(
            A2aJsonRpcCodec::encodeRequest('SendMessage', $first, 'a'),
            A2aJsonRpcCodec::encodeRequest('SendMessage', $second, 'b'),
        );
        self::assertSame(64, strlen(A2aJsonRpcCodec::digest($first)));
    }

    #[Test]
    public function anotherPayloadUnderTheSameMessageIdHasAnotherDigest(): void
    {
        $first  = A2aJsonRpcCodec::encodeParams(['message' => A2aMessage::userText('m-1', 'hello')->toArray()]);
        $second = A2aJsonRpcCodec::encodeParams(['message' => A2aMessage::userText('m-1', 'hello!')->toArray()]);

        self::assertNotSame(A2aJsonRpcCodec::digest($first), A2aJsonRpcCodec::digest($second));
    }

    #[Test]
    public function aRealResultIsReadWhenTheIdMatches(): void
    {
        $fixture  = WireFixture::load('get-task-working');
        $response = A2aJsonRpcCodec::decodeResponse($fixture->body, $fixture->requestId);

        self::assertFalse($response->isError());
        self::assertSame('TASK_STATE_WORKING', $response->result['status']['state'] ?? null);
    }

    #[Test]
    public function aRealErrorIsReadWhenTheIdMatches(): void
    {
        $fixture  = WireFixture::load('error-task-not-found');
        $response = A2aJsonRpcCodec::decodeResponse($fixture->body, $fixture->requestId);

        self::assertTrue($response->isError());
        self::assertTrue($response->error->is(A2aErrorCode::TASK_NOT_FOUND));
    }

    #[Test]
    public function aResponseToAnotherRequestIsRefused(): void
    {
        $fixture = WireFixture::load('get-task-working');

        $this->expectException(A2aInvalidAgentResponseException::class);
        $this->expectExceptionCode(1799100103);

        A2aJsonRpcCodec::decodeResponse($fixture->body, 'not-' . $fixture->requestId);
    }

    #[Test]
    public function aNumericIdDoesNotMatchTheSameDigitsAsAString(): void
    {
        $this->expectException(A2aInvalidAgentResponseException::class);
        $this->expectExceptionCode(1799100103);

        A2aJsonRpcCodec::decodeResponse('{"jsonrpc":"2.0","id":7,"result":{}}', '7');
    }

    #[Test]
    public function anErrorWithANullIdIsReadAsTheServerCouldNotReadOurId(): void
    {
        $response = A2aJsonRpcCodec::decodeResponse(
            '{"jsonrpc":"2.0","id":null,"error":{"code":-32700,"message":"Parse error"}}',
            'req-1',
        );

        self::assertTrue($response->isError());
        self::assertTrue($response->error->is(A2aErrorCode::PARSE_ERROR));
    }

    #[Test]
    public function aResultWithANullIdIsRefused(): void
    {
        $this->expectException(A2aInvalidAgentResponseException::class);
        $this->expectExceptionCode(1799100103);

        A2aJsonRpcCodec::decodeResponse('{"jsonrpc":"2.0","id":null,"result":{}}', 'req-1');
    }

    #[Test]
    public function unknownEnvelopeMembersAreIgnored(): void
    {
        $response = A2aJsonRpcCodec::decodeResponse(
            '{"jsonrpc":"2.0","id":"r","result":{"id":"t"},"traceId":"x","future":{"a":1}}',
            'r',
        );

        self::assertSame(['id' => 't'], $response->result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'not JSON' => ['<html>502 Bad Gateway</html>'];
        yield 'a JSON array' => ['[{"jsonrpc":"2.0"}]'];
        yield 'no jsonrpc member' => ['{"id":"r","result":{}}'];
        yield 'jsonrpc 1.0' => ['{"jsonrpc":"1.0","id":"r","result":{}}'];
        yield 'neither result nor error' => ['{"jsonrpc":"2.0","id":"r"}'];
        yield 'both result and error' => ['{"jsonrpc":"2.0","id":"r","result":{},"error":{"code":-32603,"message":"x"}}'];
        yield 'a result that is a string' => ['{"jsonrpc":"2.0","id":"r","result":"ok"}'];
        yield 'a result that is a list' => ['{"jsonrpc":"2.0","id":"r","result":[1,2]}'];
        yield 'an error without a code' => ['{"jsonrpc":"2.0","id":"r","error":{"message":"x"}}'];
        yield 'an error without a message' => ['{"jsonrpc":"2.0","id":"r","error":{"code":-32001}}'];
    }

    #[Test]
    #[DataProvider('malformedBodies')]
    public function aBodyThatIsNotAJsonRpcResponseIsInvalid(string $body): void
    {
        $this->expectException(A2aInvalidAgentResponseException::class);

        A2aJsonRpcCodec::decodeResponse($body, 'r');
    }
}
