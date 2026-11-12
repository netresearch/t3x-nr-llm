<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aAgentCardBuilder;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aInboundClientRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aRpcServer;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aServerMiddleware;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aServerSettings;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aTckFixtureHandler;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aTextDomainHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use stdClass;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * The installation the server-role tests run against (spec 051 stage 3): one
 * site with the A2A base URL https://agent.example.org/a2a, a technical
 * backend user (7), an administrator (1), LLM configuration 1, and three
 * publication profiles:
 *
 * - 1 `summary`: public, text handler;
 * - 2 `check`: public, text handler;
 * - 3 `internal`: not public;
 * - 4 `tck`: public, the TCK fixture.
 *
 * Client 1 may call `summary` only, client 2 `summary` and `check`, client 3
 * the fixture. A live worker heartbeat exists unless a test removes it.
 */
trait A2aServerFixture
{
    private const BASE = 'https://agent.example.org/a2a';

    private const SERVER_SETTINGS = ['enabled' => '1', 'blockingWaitSeconds' => '1', 'maxWaitingRequests' => '2', 'maxRequestBytes' => '4096'];

    /** @var array<int, string> client uid => token */
    private array $tokens = [];

    private function setUpServer(int $blockingWaitSeconds = 1, int $maxWaitingRequests = 2): void
    {
        $this->serverSettings(['blockingWaitSeconds' => (string) $blockingWaitSeconds, 'maxWaitingRequests' => (string) $maxWaitingRequests] + self::SERVER_SETTINGS);

        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');
        $this->importFixture('LlmConfigurations.csv');
        $this->db('be_users')->insert('be_users', ['uid' => 7, 'username' => 'a2a-technical', 'admin' => 0, 'usergroup' => '']);
        $this->db('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1]);

        $profiles = [
            1 => ['summary', A2aTextDomainHandler::IDENTIFIER, 1],
            2 => ['check', A2aTextDomainHandler::IDENTIFIER, 1],
            3 => ['internal', A2aTextDomainHandler::IDENTIFIER, 0],
            4 => ['tck', A2aTckFixtureHandler::IDENTIFIER, 1],
        ];
        foreach ($profiles as $uid => [$skill, $handler, $public]) {
            $this->db('tx_nrllm_a2a_profile')->insert('tx_nrllm_a2a_profile', [
                'uid'  => $uid, 'skill_id' => $skill, 'name' => ucfirst($skill), 'description' => 'The ' . $skill . ' skill',
                'tags' => $skill, 'handler' => $handler, 'configuration_uid' => 1, 'public' => $public,
            ]);
        }

        foreach ([1 => '1', 2 => '1,2', 3 => '4', 4 => '1,3'] as $uid => $profileList) {
            $this->db('tx_nrllm_a2a_client')->insert('tx_nrllm_a2a_client', [
                'uid' => $uid, 'title' => 'Client ' . $uid, 'be_user' => 7, 'workspace' => 0, 'profiles' => $profileList, 'task_limit' => 4, 'enabled' => 1,
            ]);
            $this->tokens[$uid] = $this->getService(A2aInboundClientRepository::class)->issueToken($uid);
        }

        $this->heartbeat();
        $this->getService(CacheManager::class)->getCache('ratelimiter')->flush();
    }

    /**
     * The extension configuration `a2a.server`; null removes it.
     *
     * @param array<string, string>|null $settings
     */
    private function serverSettings(?array $settings): void
    {
        $configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        $extensions    = is_array($configuration) && is_array($configuration['EXTENSIONS'] ?? null) ? $configuration['EXTENSIONS'] : [];
        $nrLlm         = is_array($extensions['nr_llm'] ?? null) ? $extensions['nr_llm'] : [];
        $a2a           = is_array($nrLlm['a2a'] ?? null) ? $nrLlm['a2a'] : [];
        if ($settings === null) {
            unset($a2a['server']);
        } else {
            $a2a['server'] = $settings;
        }

        $nrLlm['a2a']                = $a2a;
        $extensions['nr_llm']        = $nrLlm;
        $configuration               = is_array($configuration) ? $configuration : [];
        $configuration['EXTENSIONS'] = $extensions;
        $GLOBALS['TYPO3_CONF_VARS']  = $configuration;
    }

    private function heartbeat(): void
    {
        $this->db('tx_nrllm_worker_heartbeat')->insert('tx_nrllm_worker_heartbeat', ['worker_id' => 'w-1', 'transport' => 'doctrine', 'last_seen' => time()]);
    }

    private function middleware(?string $siteBase = self::BASE): A2aServerMiddleware
    {
        $site     = new Site('agent', 1, ['base' => 'https://agent.example.org/', 'websiteTitle' => 'Agent', 'settings' => $siteBase === null ? [] : ['nrllm' => ['a2a' => ['baseUrl' => $siteBase]]]]);
        $finder   = new A2aOneSiteFinder($site);
        $settings = new A2aServerSettings($this->getService(ExtensionConfiguration::class), $finder);

        return new A2aServerMiddleware($settings, $finder, $this->getService(A2aAgentCardBuilder::class), $this->getService(A2aRpcServer::class));
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private function call(string $method, array $params, ?int $client = 1, array $headers = [], string $remote = '203.0.113.5'): array
    {
        $response = $this->post(json_encode(['jsonrpc' => '2.0', 'id' => 'r-1', 'method' => $method, 'params' => $params === [] ? new stdClass() : $params], JSON_THROW_ON_ERROR), $client, $headers, $remote);
        $decoded  = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return self::objectOf($decoded) + ['_status' => $response->getStatusCode(), '_headers' => array_map(static fn (array $values): string => implode(', ', $values), $response->getHeaders())];
    }

    /**
     * @param array<string, string> $headers
     */
    private function post(string $body, ?int $client = 1, array $headers = [], string $remote = '203.0.113.5', string $url = self::BASE): ResponseInterface
    {
        $headers += ['Content-Type' => 'application/json', 'A2A-Version' => '1.0'];
        if ($client !== null && !array_key_exists('Authorization', $headers)) {
            $headers['Authorization'] = 'Bearer ' . $this->tokens[$client];
        }

        return $this->middleware()->process($this->request('POST', $url, $headers, $body, $remote), $this->passThrough());
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $method, string $url, array $headers, string $body = '', string $remote = '203.0.113.5'): ServerRequestInterface
    {
        $parts  = parse_url($url);
        $host   = ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $server = ['HTTP_HOST' => $host, 'REMOTE_ADDR' => $remote, 'REQUEST_METHOD' => $method, 'SERVER_PORT' => $parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80)];
        if (($parts['scheme'] ?? '') === 'https') {
            $server['HTTPS'] = 'on';
        }

        $stream = new Stream('php://temp', 'rw');
        $stream->write($body);
        $stream->rewind();

        $request = new ServerRequest($url, $method, $stream, $headers, $server);
        parse_str($parts['query'] ?? '', $query);

        $request = $request->withQueryParams($query);

        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    private static function passedThrough(ResponseInterface $response): bool
    {
        return $response->getHeaderLine('X-Test-Passed-Through') === '1';
    }

    private function passThrough(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public const PASSED_THROUGH = 'X-Test-Passed-Through';

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('php://temp', 200, [self::PASSED_THROUGH => '1']);
            }
        };
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function message(string $text = 'Summarise this.', string $messageId = '', array $extra = []): array
    {
        return ['messageId' => $messageId !== '' ? $messageId : bin2hex(random_bytes(8)), 'role' => 'ROLE_USER', 'parts' => [['text' => $text]]] + $extra;
    }

    /**
     * @param array<string, mixed> $reply
     *
     * @return array{code: int, reason: string, http: int}
     */
    private function errorOf(array $reply): array
    {
        $error = $reply['error'] ?? null;
        self::assertIsArray($error, 'expected an error: ' . json_encode($reply));
        self::assertIsArray(self::j($error, 'data'));
        self::assertTrue(array_is_list(self::j($error, 'data')), 'error.data is an array (A2A-P6)');
        $info = self::j($error, 'data.0');
        self::assertIsArray($info);
        self::assertSame('type.googleapis.com/google.rpc.ErrorInfo', $info['@type'] ?? null);

        return ['code' => (int) self::text(self::j($error, 'code')), 'reason' => self::text($info['reason'] ?? ''), 'http' => (int) self::text($reply['_status'] ?? '')];
    }

    /**
     * @param array<string, mixed> $reply
     *
     * @return array<string, mixed>
     */
    private function taskOf(array $reply): array
    {
        $result = $reply['result'] ?? null;
        self::assertIsArray($result, 'expected a result: ' . json_encode($reply));
        $task = $result['task'] ?? $result;

        return self::objectOf($task);
    }

    /**
     * @param array<string, mixed> $where
     */
    private function rows(string $table, array $where = []): int
    {
        return $this->db($table)->count('*', $table, $where);
    }

    /**
     * The value at a dot path of decoded JSON (a number indexes a list),
     * null when any step is missing.
     */
    private static function j(mixed $value, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            if (!is_array($value)) {
                return null;
            }

            $value = $value[ctype_digit($key) ? (int) $key : $key] ?? null;
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function objectOf(mixed $value): array
    {
        self::assertIsArray($value);
        $object = [];
        foreach ($value as $key => $item) {
            $object[(string) $key] = $item;
        }

        return $object;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rowsOf(mixed $value): array
    {
        self::assertIsArray($value);

        return array_values(array_map(self::objectOf(...), $value));
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function db(string $table): Connection
    {
        return $this->getService(ConnectionPool::class)->getConnectionForTable($table);
    }
}
