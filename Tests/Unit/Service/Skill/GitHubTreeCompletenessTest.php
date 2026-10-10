<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill;

use GuzzleHttp\Psr7\Response;
use Netresearch\NrLlm\Service\Skill\Exception\GitHubApiException;
use Netresearch\NrLlm\Service\Skill\GitHubClient;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Log\NullLogger;
use Throwable;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;

#[CoversClass(GitHubClient::class)]
final class GitHubTreeCompletenessTest extends TestCase
{
    #[Test]
    #[DataProvider('truncatedTrees')]
    public function explicitlyTruncatedTreeIsNeverReportedAsComplete(
        string $json,
    ): void {
        $client = $this->client($json);
        $failure = null;
        try {
            $client->listTree('acme', 'skills', 'sha', null);
        } catch (Throwable $caught) {
            $failure = $caught;
        }

        self::assertInstanceOf(GitHubApiException::class, $failure);
        self::assertFalse($failure->isRateLimit);
        self::assertSame(0, $failure->status);
        self::assertStringContainsString('truncated', $failure->getMessage());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function truncatedTrees(): iterable
    {
        yield 'nonempty prefix' => [
            '{"truncated":true,"tree":[{"type":"blob","path":"skills/a/SKILL.md"}]}',
        ];
        yield 'empty prefix' => ['{"truncated":true,"tree":[]}'];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('completeTrees')]
    public function completeAndLegacyResponsesPreserveBlobPaths(
        string $json,
        array $expected,
    ): void {
        $failure = null;
        $paths = null;
        try {
            $paths = $this->client($json)->listTree('acme', 'skills', 'sha', null);
        } catch (Throwable $caught) {
            $failure = $caught;
        }

        self::assertNull($failure);
        self::assertSame($expected, $paths);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function completeTrees(): iterable
    {
        yield 'explicit complete' => [
            '{"truncated":false,"tree":[{"type":"tree","path":"skills"},{"type":"blob","path":"skills/a/SKILL.md"}]}',
            ['skills/a/SKILL.md'],
        ];
        yield 'complete empty' => ['{"truncated":false,"tree":[]}', []];
        yield 'legacy without flag' => ['{"tree":[{"type":"blob","path":"SKILL.md"}]}', ['SKILL.md']];
    }

    private function client(string $json): GitHubClient
    {
        $transport = self::createStub(ClientInterface::class);
        $transport
            ->method('sendRequest')
            ->willReturn(new Response(200, [], $json));
        $client = new GitHubClient(
            self::createStub(VaultServiceInterface::class),
            new RequestFactory(new GuzzleClientFactory()),
            new NullLogger(),
        );
        $client->setHttpClient($transport);

        return $client;
    }
}
