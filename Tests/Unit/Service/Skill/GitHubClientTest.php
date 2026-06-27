<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill;

use Netresearch\NrLlm\Service\Skill\Exception\HostNotAllowedException;
use Netresearch\NrLlm\Service\Skill\GitHubClient;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
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
}
