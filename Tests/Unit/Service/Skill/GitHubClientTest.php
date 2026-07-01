<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill;

use Netresearch\NrLlm\Service\Skill\Exception\GitHubApiException;
use Netresearch\NrLlm\Service\Skill\Exception\HostNotAllowedException;
use Netresearch\NrLlm\Service\Skill\GitHubClient;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;

#[CoversClass(GitHubClient::class)]
final class GitHubClientTest extends TestCase
{
    /**
     * @param callable(RequestInterface): ResponseInterface $handler
     */
    private function clientReturning(callable $handler): GitHubClient
    {
        // nr-vault transport is bypassed because setHttpClient() injects the stub below.
        $client = new GitHubClient(
            self::createStub(VaultServiceInterface::class),
            new RequestFactory(new GuzzleClientFactory()),
            new NullLogger(),
        );
        $stub = new class ($handler) implements ClientInterface {
            /** @var callable(RequestInterface): ResponseInterface */
            private $handler;

            /**
             * @param callable(RequestInterface): ResponseInterface $handler
             */
            public function __construct(callable $handler)
            {
                $this->handler = $handler;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return ($this->handler)($request);
            }
        };
        $client->setHttpClient($stub);
        return $client;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function json(array $data): ResponseInterface
    {
        $body = new Stream('php://temp', 'rw');
        $body->write((string)json_encode($data));
        $body->rewind();
        return (new Response())->withBody($body)->withStatus(200);
    }

    #[Test]
    public function resolveShaReadsCommitSha(): void
    {
        $client = $this->clientReturning(fn(RequestInterface $r) => $this->json(['sha' => 'abc123sha']));
        self::assertSame('abc123sha', $client->resolveSha('o', 'r', 'main', null));
    }

    #[Test]
    public function listTreeReturnsBlobPaths(): void
    {
        $client = $this->clientReturning(fn(RequestInterface $r) => $this->json([
            'tree' => [
                ['path' => 'SKILL.md', 'type' => 'blob'],
                ['path' => 'skills', 'type' => 'tree'],
                ['path' => 'skills/a/SKILL.md', 'type' => 'blob'],
            ],
        ]));
        self::assertSame(['SKILL.md', 'skills/a/SKILL.md'], $client->listTree('o', 'r', 'sha', null));
    }

    #[Test]
    public function listTreeThrowsOnMalformedTreeInsteadOfReportingEmpty(): void
    {
        // A 200 with a missing/non-array `tree` must NOT return [] — that would
        // make the sync orphan (delete) every previously-synced skill.
        $client = $this->clientReturning(fn(RequestInterface $r) => $this->json(['message' => 'ok but no tree']));
        $this->expectException(GitHubApiException::class);
        $client->listTree('o', 'r', 'sha', null);
    }

    #[Test]
    public function fetchAllowedUrlRejectsNonGitHubHost(): void
    {
        $client = $this->clientReturning(fn(RequestInterface $r) => $this->json([]));
        $this->expectException(HostNotAllowedException::class);
        $client->fetchAllowedUrl('https://evil.example.com/marketplace.json', null);
    }

    #[Test]
    public function fetchAllowedUrlRejectsNonHttpsScheme(): void
    {
        $client = $this->clientReturning(fn(RequestInterface $r) => $this->json([]));
        $this->expectException(HostNotAllowedException::class);
        $client->fetchAllowedUrl('http://raw.githubusercontent.com/o/r/sha/SKILL.md', null);
    }

    #[Test]
    public function exhaustedPrimaryBudgetOn403IsFlaggedAsRateLimit(): void
    {
        $client = $this->clientReturning(
            fn(RequestInterface $r) => $this->httpResponse(403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '4711']),
        );
        $e = $this->captureFailure($client);
        self::assertTrue($e->isRateLimit, '403 with X-RateLimit-Remaining: 0 must be a rate-limit failure');
    }

    #[Test]
    public function http429IsFlaggedAsRateLimit(): void
    {
        $client = $this->clientReturning(fn(RequestInterface $r) => $this->httpResponse(429));
        $e = $this->captureFailure($client);
        self::assertTrue($e->isRateLimit, 'HTTP 429 must be a rate-limit failure');
    }

    #[Test]
    public function http500IsNotARateLimit(): void
    {
        $client = $this->clientReturning(fn(RequestInterface $r) => $this->httpResponse(500));
        $e = $this->captureFailure($client);
        self::assertFalse($e->isRateLimit, 'a server error must stay a generic (non-rate-limit) failure');
        self::assertSame(500, $e->status);
    }

    #[Test]
    public function redirectIsRejectedAndNeverSilentlyConsumed(): void
    {
        // 3xx must be treated as an error: a followed redirect could escape the host allowlist.
        $client = $this->clientReturning(fn(RequestInterface $r) => $this->httpResponse(302, ['Location' => 'https://evil.example.com/']));
        $e = $this->captureFailure($client);
        self::assertFalse($e->isRateLimit);
        self::assertSame(302, $e->status);
    }

    /**
     * @param array<string,string> $headers
     */
    private function httpResponse(int $status, array $headers = []): ResponseInterface
    {
        $response = (new Response())->withStatus($status);
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }

    private function captureFailure(GitHubClient $client): GitHubApiException
    {
        try {
            $client->fetchAllowedUrl('https://api.github.com/repos/o/r/commits/main', null);
        } catch (GitHubApiException $e) {
            return $e;
        }
        self::fail('expected a GitHubApiException to be thrown');
    }
}
