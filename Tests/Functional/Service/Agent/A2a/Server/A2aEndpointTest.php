<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aAuthenticator;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aInboundClientRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aRpcError;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aRpcServer;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aServerMiddleware;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Stringable;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\RateLimiter\Storage\CachingFrameworkStorage;

/**
 * The endpoint of the server role (spec 051 A2A-SV1 to A2A-SV3, A2A-P1 to
 * A2A-P7, A2A-SEC1, A2A-SEC6, A2A-OPS2).
 */
#[CoversClass(A2aServerMiddleware::class)]
#[CoversClass(A2aRpcServer::class)]
#[CoversClass(A2aAuthenticator::class)]
final class A2aEndpointTest extends AbstractFunctionalTestCase
{
    use A2aServerFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServer();
    }

    /**
     * A2A-SV3: the card lists the public profiles, its URLs come from the
     * configured base, and it carries Cache-Control and an ETag.
     */
    #[Test]
    public function theCardListsThePublicProfilesUnderTheConfiguredBase(): void
    {
        $response = $this->middleware()->process($this->request('GET', self::BASE . '/.well-known/agent-card.json', []), $this->passThrough());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('max-age=300', $response->getHeaderLine('Cache-Control'));
        $body = (string) $response->getBody();
        self::assertSame('"' . hash('sha256', $body) . '"', $response->getHeaderLine('ETag'));
        $card = json_decode($body, true);
        self::assertIsArray($card);
        self::assertSame([['url' => self::BASE, 'protocolBinding' => 'JSONRPC', 'protocolVersion' => '1.0']], $card['supportedInterfaces']);
        self::assertSame(['check', 'summary', 'tck'], array_column($card['skills'], 'id'));
        self::assertSame(['bearer' => ['httpAuthSecurityScheme' => ['scheme' => 'Bearer']]], $card['securitySchemes']);
        self::assertSame([['schemes' => ['bearer' => ['list' => []]]]], $card['securityRequirements']);
        self::assertFalse(self::j($card, 'capabilities.streaming'));
        self::assertFalse(self::j($card, 'capabilities.pushNotifications'));
        self::assertArrayNotHasKey('extendedAgentCard', $card['capabilities']);
        self::assertSame('https://nr-llm.netresearch.de/a2a/ext/profile/v1', self::j($card, 'capabilities.extensions.0.uri'));
        self::assertFalse(self::j($card, 'capabilities.extensions.0.required'));
        self::assertSame(['text/plain'], $card['defaultInputModes']);
        self::assertSame(['text/plain'], $card['defaultOutputModes']);
    }

    /**
     * ADR-282: a public profile that cannot be served now is not advertised:
     * its handler is not available here, or its configuration keeps no result
     * under the current privacy policy.
     */
    #[Test]
    public function theCardLeavesOutAProfileThatCannotBeServed(): void
    {
        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['handler' => 'nr_llm.not_registered'], ['skill_id' => 'check']);
        self::assertSame(['summary', 'tck'], array_column($this->card()['skills'], 'id'));

        // No skill left: no card, the request passes on (A2A-P5).
        $this->db('tx_nrllm_provider')->update('tx_nrllm_provider', ['privacy_level' => 'none'], []);
        self::assertTrue(self::passedThrough($this->middleware()->process($this->request('GET', self::BASE . '/.well-known/agent-card.json', []), $this->passThrough())));
    }

    /**
     * @return array<string, mixed>
     */
    private function card(): array
    {
        $card = json_decode((string) $this->middleware()->process($this->request('GET', self::BASE . '/.well-known/agent-card.json', []), $this->passThrough())->getBody(), true);
        self::assertIsArray($card);
        self::assertIsArray($card['skills'] ?? null);

        /** @var array<string, mixed> $card */
        return $card;
    }

    #[Test]
    public function aMatchingIfNoneMatchIsAnsweredWith304(): void
    {
        $first = $this->middleware()->process($this->request('GET', self::BASE . '/.well-known/agent-card.json', []), $this->passThrough());

        $second = $this->middleware()->process($this->request('GET', self::BASE . '/.well-known/agent-card.json', ['If-None-Match' => $first->getHeaderLine('ETag')]), $this->passThrough());

        self::assertSame(304, $second->getStatusCode());
        self::assertSame('', (string) $second->getBody());
    }

    /**
     * A2A-SV2: a request whose host is not the configured base's is not an
     * A2A request; nothing is built from the Host header.
     */
    #[Test]
    public function aRequestToAnotherHostOrPathPassesThrough(): void
    {
        foreach (['https://evil.example.net/a2a/.well-known/agent-card.json', 'http://agent.example.org/a2a/.well-known/agent-card.json', 'https://agent.example.org/other/.well-known/agent-card.json', 'https://agent.example.org/a2a/elsewhere'] as $url) {
            self::assertTrue(self::passedThrough($this->middleware()->process($this->request('GET', $url, []), $this->passThrough())), $url);
        }

        self::assertTrue(self::passedThrough($this->post('{}', 1, [], '203.0.113.5', 'https://evil.example.net/a2a')));
    }

    /**
     * A2A-SV1 and A2A-OPS2: without a base URL, or with the server role
     * switched off, a site answers neither path.
     */
    #[Test]
    public function aSiteWithoutABaseUrlOrASwitchedOffServerAnswersNothing(): void
    {
        self::assertTrue(self::passedThrough($this->middleware(null)->process($this->request('GET', self::BASE . '/.well-known/agent-card.json', []), $this->passThrough())));

        $this->serverSettings(['enabled' => '0'] + self::SERVER_SETTINGS);

        self::assertTrue(self::passedThrough($this->middleware()->process($this->request('GET', self::BASE . '/.well-known/agent-card.json', []), $this->passThrough())));
        self::assertTrue(self::passedThrough($this->post('{}')));
    }

    /**
     * A2A-SEC1: one refusal for a missing, malformed, unknown, wrong,
     * disabled and expired credential and for an unusable technical user.
     */
    #[Test]
    public function everyAuthenticationFailureGetsTheSameRefusal(): void
    {
        $this->db('tx_nrllm_a2a_client')->update('tx_nrllm_a2a_client', ['enabled' => 0], ['uid' => 2]);
        $this->db('tx_nrllm_a2a_client')->update('tx_nrllm_a2a_client', ['expires' => time() - 10], ['uid' => 3]);
        $this->db('tx_nrllm_a2a_client')->update('tx_nrllm_a2a_client', ['be_user' => 1], ['uid' => 4]);
        $wrong = substr($this->tokens[1], 0, -4) . 'AAAA';

        $bodies = [];
        foreach ([
            'missing'   => [],
            'malformed' => ['Authorization' => 'Bearer not-a-token'],
            'basic'     => ['Authorization' => 'Basic ' . base64_encode('a:b')],
            'unknown'   => ['Authorization' => 'Bearer nra2a_0123456789abcdef_' . str_repeat('A', 43)],
            'wrong'     => ['Authorization' => 'Bearer ' . $wrong],
            'disabled'  => ['Authorization' => 'Bearer ' . $this->tokens[2]],
            'expired'   => ['Authorization' => 'Bearer ' . $this->tokens[3]],
            'admin'     => ['Authorization' => 'Bearer ' . $this->tokens[4]],
        ] as $case => $headers) {
            $response = $this->post('{"jsonrpc":"2.0","id":"r-1","method":"GetTask","params":{"id":"x"}}', null, $headers, '198.51.100.' . (count($bodies) + 1));
            self::assertSame(401, $response->getStatusCode(), $case);
            self::assertSame('Bearer realm="a2a"', $response->getHeaderLine('WWW-Authenticate'), $case);
            $bodies[$case] = (string) $response->getBody();
        }

        self::assertCount(1, array_unique($bodies), 'the refusals differ: ' . json_encode($bodies));
        $error = self::j(json_decode($bodies['missing'], true), 'error') ?? [];
        self::assertSame([-32000, 'UNAUTHENTICATED'], [self::j($error, 'code'), self::j($error, 'data.0.reason')]);
    }

    /**
     * A2A-SEC1: failures are logged with the source address, never with the
     * token, and rate-limited per source address.
     */
    #[Test]
    public function failedAttemptsAreLoggedWithoutTheTokenAndLimitedPerAddress(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->lines[] = $message . ' ' . json_encode($context);
            }
        };
        $authenticator = new A2aAuthenticator($this->getService(A2aInboundClientRepository::class), $this->getService(ConnectionPool::class), $this->getService(CachingFrameworkStorage::class), $logger);
        $wrong         = substr($this->tokens[1], 0, -4) . 'AAAA';

        $refusals = [];
        for ($attempt = 1; $attempt <= 11; ++$attempt) {
            try {
                $authenticator->authenticate($this->request('POST', self::BASE, ['Authorization' => 'Bearer ' . $wrong]), time());
            } catch (A2aRpcError $error) {
                $refusals[] = $error->reason;
            }
        }

        self::assertSame(array_merge(array_fill(0, 10, 'UNAUTHENTICATED'), ['RATE_LIMITED']), $refusals);
        self::assertCount(10, $logger->lines);
        self::assertStringContainsString('203.0.113.5', $logger->lines[0]);
        foreach ($logger->lines as $line) {
            self::assertStringNotContainsString($wrong, $line);
            self::assertStringNotContainsString(substr($wrong, 23), $line);
        }

        // Another address is not limited, and a valid token there passes.
        self::assertSame(1, $authenticator->authenticate($this->request('POST', self::BASE, ['Authorization' => 'Bearer ' . $this->tokens[1]], '', '192.0.2.9'), time())->uid);

        // Only failures are limited: a valid token from the limited address
        // passes too, so failed requests cannot lock a registered client out.
        self::assertSame(1, $authenticator->authenticate($this->request('POST', self::BASE, ['Authorization' => 'Bearer ' . $this->tokens[1]]), time())->uid);
    }

    /**
     * ADR-280: the token is not stored; its hash and public prefix are.
     */
    #[Test]
    public function theTokenIsStoredOnlyAsItsHash(): void
    {
        $row = $this->db('tx_nrllm_a2a_client')->select(['*'], 'tx_nrllm_a2a_client', ['uid' => 1])->fetchAssociative();
        self::assertIsArray($row);

        self::assertStringNotContainsString($this->tokens[1], json_encode($row, JSON_THROW_ON_ERROR));
        self::assertSame(hash('sha256', $this->tokens[1]), $row['token_hash']);
        self::assertSame(A2aInboundClientRepository::prefixOf($this->tokens[1]), $row['token_prefix']);
    }

    /**
     * A rotated token replaces the old one on the same record.
     */
    #[Test]
    public function aRotatedTokenReplacesTheOldOne(): void
    {
        $old             = $this->tokens[1];
        $this->tokens[1] = $this->getService(A2aInboundClientRepository::class)->issueToken(1);

        self::assertSame(401, $this->post('{"jsonrpc":"2.0","id":1,"method":"GetTask","params":{"id":"x"}}', null, ['Authorization' => 'Bearer ' . $old])->getStatusCode());
        self::assertSame(-32001, self::j($this->errorOf($this->call('GetTask', ['id' => 'x'])), 'code'));
    }

    /**
     * A2A-SEC6: a body over the limit is refused with 413 before it is
     * parsed, also without a Content-Length.
     */
    #[Test]
    public function aBodyOverTheLimitIsRefusedBeforeItIsParsed(): void
    {
        $response = $this->post(str_repeat('x', 5000));

        self::assertSame(413, $response->getStatusCode());
        self::assertSame('REQUEST_TOO_LARGE', self::j(json_decode((string) $response->getBody(), true), 'error.data.0.reason'));
        self::assertSame(413, $this->post('{}', 1, ['Content-Length' => '99999'])->getStatusCode());
    }

    /**
     * A2A-P2: 1.0 and 1.0.x, by header or query parameter; absent is 0.3.
     *
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('versions')]
    public function theProtocolVersionIsNegotiatedIgnoringThePatchLevel(array $headers, string $url, int $code): void
    {
        $body  = '{"jsonrpc":"2.0","id":"v","method":"GetTask","params":{"id":"unknown"}}';
        $reply = json_decode((string) $this->post($body, 1, $headers, '203.0.113.5', $url)->getBody(), true);

        self::assertSame($code, self::j($reply, 'error.code'));
    }

    /**
     * @return iterable<string, array{array<string, string>, string, int}>
     */
    public static function versions(): iterable
    {
        yield '1.0' => [['A2A-Version' => '1.0'], self::BASE, -32001];
        yield '1.0.7' => [['A2A-Version' => '1.0.7'], self::BASE, -32001];
        yield 'empty means 0.3' => [['A2A-Version' => ''], self::BASE, -32009];
        yield '0.3' => [['A2A-Version' => '0.3'], self::BASE, -32009];
        yield '1.1' => [['A2A-Version' => '1.1'], self::BASE, -32009];
        yield 'an empty header wins over the query' => [['A2A-Version' => ''], self::BASE . '?A2A-Version=1.0', -32009];
    }

    #[Test]
    public function theQueryParameterCountsWhenTheHeaderIsAbsent(): void
    {
        $response = $this->middleware()->process(
            $this->request('POST', self::BASE . '?A2A-Version=1.0.1', ['Authorization' => 'Bearer ' . $this->tokens[1], 'Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":"v","method":"GetTask","params":{"id":"unknown"}}'),
            $this->passThrough(),
        );

        self::assertSame(-32001, self::j(json_decode((string) $response->getBody(), true), 'error.code'));
        self::assertSame(-32009, self::j($this->errorOf($this->call('GetTask', ['id' => 'x'], 1, ['A2A-Version' => ''])), 'code'));
    }

    /**
     * A2A-P1, A2A-P7: an unknown method is -32601; the methods of later
     * stages are refused by capability.
     */
    #[Test]
    #[DataProvider('methods')]
    public function methodsAreRefusedByCapability(string $method, int $code): void
    {
        self::assertSame($code, self::j($this->errorOf($this->call($method, ['id' => 'x', 'taskId' => 'x'])), 'code'));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function methods(): iterable
    {
        yield 'unknown' => ['tasks/get', -32601];
        yield 'SendStreamingMessage' => ['SendStreamingMessage', -32004];
        yield 'SubscribeToTask' => ['SubscribeToTask', -32004];
        yield 'GetExtendedAgentCard' => ['GetExtendedAgentCard', -32004];
        yield 'CreateTaskPushNotificationConfig' => ['CreateTaskPushNotificationConfig', -32003];
        yield 'GetTaskPushNotificationConfig' => ['GetTaskPushNotificationConfig', -32003];
        yield 'ListTaskPushNotificationConfigs' => ['ListTaskPushNotificationConfigs', -32003];
        yield 'DeleteTaskPushNotificationConfig' => ['DeleteTaskPushNotificationConfig', -32003];
    }

    /**
     * A2A-P7: a SendMessage with an embedded push config is refused while
     * push is undeclared, before anything is stored.
     */
    #[Test]
    public function anEmbeddedPushConfigIsRefused(): void
    {
        $reply = $this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true, 'taskPushNotificationConfig' => ['url' => 'https://example.org/hook']]]);

        self::assertSame(-32003, self::j($this->errorOf($reply), 'code'));
        self::assertSame(0, $this->rows('tx_nrllm_a2a_task'));
    }

    /**
     * A2A-SV6: a non-empty tenant is refused on every method.
     */
    #[Test]
    public function aTenantIsRefused(): void
    {
        foreach (['SendMessage', 'GetTask', 'ListTasks', 'CancelTask'] as $method) {
            $error = $this->errorOf($this->call($method, ['tenant' => 'acme', 'id' => 'x', 'message' => $this->message()]));
            self::assertSame(-32602, self::j($error, 'code'), $method);
        }
    }

    /**
     * JSON-RPC envelope errors.
     */
    #[Test]
    public function envelopeErrorsAreJsonRpcErrors(): void
    {
        self::assertSame(-32700, self::j(json_decode((string) $this->post('{not json')->getBody(), true), 'error.code'));
        self::assertSame(-32600, self::j(json_decode((string) $this->post('[{"jsonrpc":"2.0","id":1,"method":"GetTask"}]')->getBody(), true), 'error.code'));
        $reply = json_decode((string) $this->post('{"jsonrpc":"1.0","id":"keep","method":"GetTask"}')->getBody(), true);
        self::assertSame([-32600, 'keep'], [self::j($reply, 'error.code'), self::j($reply, 'id')]);
    }

    /**
     * A2A-P4: unrecognised members are ignored.
     */
    #[Test]
    public function unknownMembersAreIgnored(): void
    {
        $reply = $this->call('SendMessage', [
            'message'       => $this->message('Summarise.', '', ['futureField' => ['x' => 1]]),
            'configuration' => ['returnImmediately' => true, 'futureOption' => true],
            'futureParam'   => 'y',
        ]);

        self::assertSame('TASK_STATE_SUBMITTED', self::j($this->taskOf($reply), 'status.state'));
    }

    /**
     * A2A-P5: a message missing a REQUIRED field is -32602 with a BadRequest
     * naming the field.
     */
    #[Test]
    public function aMissingRequiredFieldIsNamed(): void
    {
        $reply = $this->call('SendMessage', ['message' => ['role' => 'ROLE_USER', 'parts' => [['text' => 'x']]]]);

        self::assertSame(-32602, self::j($this->errorOf($reply), 'code'));
        $badRequest = self::j($reply, 'error.data.1') ?? [];
        self::assertSame('type.googleapis.com/google.rpc.BadRequest', self::j($badRequest, '@type'));
        self::assertSame('message.messageId', self::j($badRequest, 'fieldViolations.0.field'));
        self::assertSame(-32602, self::j($this->errorOf($this->call('SendMessage', ['message' => ['messageId' => 'm', 'role' => 'ROLE_USER', 'parts' => []]])), 'code'));
        self::assertSame(-32602, self::j($this->errorOf($this->call('SendMessage', [])), 'code'));
    }

    /**
     * Stage 3 accepts text/plain only (the card's input modes); a file or
     * data part is ContentTypeNotSupported.
     */
    #[Test]
    public function aNonTextPartIsAnUnsupportedContentType(): void
    {
        $reply = $this->call('SendMessage', ['message' => ['messageId' => 'm-raw', 'role' => 'ROLE_USER', 'parts' => [['raw' => base64_encode('x'), 'mediaType' => 'image/png']]]]);

        self::assertSame(-32005, self::j($this->errorOf($reply), 'code'));
        self::assertSame(-32005, self::j($this->errorOf($this->call('SendMessage', ['message' => ['messageId' => 'm-html', 'role' => 'ROLE_USER', 'parts' => [['text' => '<b>x</b>', 'mediaType' => 'text/html']]]])), 'code'));
        self::assertSame(-32005, self::j($this->errorOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['acceptedOutputModes' => ['image/png']]])), 'code'));
        self::assertSame(0, $this->rows('tx_nrllm_a2a_task'));
    }

    #[Test]
    public function aMessageWithTooManyPartsIsRefused(): void
    {
        $parts = array_fill(0, 17, ['text' => 'x']);

        self::assertSame(-32602, self::j($this->errorOf($this->call('SendMessage', ['message' => ['messageId' => 'm-many', 'role' => 'ROLE_USER', 'parts' => $parts]])), 'code'));
    }

    #[Test]
    public function anAgentRoleMessageIsRefused(): void
    {
        self::assertSame(-32602, self::j($this->errorOf($this->call('SendMessage', ['message' => ['messageId' => 'm-agent', 'role' => 'ROLE_AGENT', 'parts' => [['text' => 'x']]]])), 'code'));
    }

    protected function tearDown(): void
    {
        $this->serverSettings(null);
        $this->getService(CacheManager::class)->getCache('ratelimiter')->flush();
        parent::tearDown();
    }
}
