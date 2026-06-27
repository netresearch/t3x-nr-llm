<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Skill\Fixtures;

use Netresearch\NrLlm\Service\Skill\Exception\GitHubApiException;
use Netresearch\NrLlm\Service\Skill\GitHubClientInterface;
use Psr\Http\Client\ClientInterface;

/**
 * In-memory GitHubClient double driving the repo, single_file and marketplace flows.
 *
 * The plain (sha, tree, bodies) constructor models a single default repo (used by repo/single_file
 * tests). For marketplace tests, register per-plugin repos via $repos (keyed by "owner/repo"), the
 * index JSON via $indexes (keyed by URL), and force a specific (owner,repo) to fail via $repoErrors
 * (e.g. an unreachable child repo or a rate-limit GitHubApiException). A path missing from a repo's
 * bodies yields a 404, exactly like the real client.
 */
final readonly class FakeGitHubClient implements GitHubClientInterface
{
    /**
     * @param list<string>                                                                  $tree       default repo tree
     * @param array<string,string>                                                          $bodies     default repo bodies (path => content)
     * @param array<string,array{sha:string,tree:list<string>,bodies:array<string,string>}> $repos      per "owner/repo" overrides
     * @param array<string,GitHubApiException>                                              $repoErrors per "owner/repo" failure to raise
     * @param array<string,string>                                                          $indexes    marketplace index JSON keyed by URL
     */
    public function __construct(
        private string $sha = '',
        private array $tree = [],
        private array $bodies = [],
        private array $repos = [],
        private array $repoErrors = [],
        private array $indexes = [],
    ) {}

    public function resolveSha(string $owner, string $repo, string $ref, ?string $tokenUuid): string
    {
        $this->guard($owner, $repo);
        return $this->repo($owner, $repo)['sha'];
    }

    public function listTree(string $owner, string $repo, string $sha, ?string $tokenUuid): array
    {
        $this->guard($owner, $repo);
        return $this->repo($owner, $repo)['tree'];
    }

    public function fetchRawBySha(string $owner, string $repo, string $sha, string $path, ?string $tokenUuid): string
    {
        $this->guard($owner, $repo);
        $bodies = $this->repo($owner, $repo)['bodies'];
        if (!array_key_exists($path, $bodies)) {
            throw GitHubApiException::forStatus(
                sprintf('https://raw.githubusercontent.com/%s/%s/%s/%s', $owner, $repo, $sha, $path),
                404,
            );
        }
        return $bodies[$path];
    }

    public function fetchAllowedUrl(string $url, ?string $tokenUuid): string
    {
        return $this->indexes[$url] ?? '';
    }

    public function setHttpClient(ClientInterface $client): void
    {
        // No-op: the fake bypasses the HTTP transport entirely.
    }

    /**
     * @return array{sha:string,tree:list<string>,bodies:array<string,string>}
     */
    private function repo(string $owner, string $repo): array
    {
        return $this->repos[$owner . '/' . $repo]
            ?? ['sha' => $this->sha, 'tree' => $this->tree, 'bodies' => $this->bodies];
    }

    private function guard(string $owner, string $repo): void
    {
        $error = $this->repoErrors[$owner . '/' . $repo] ?? null;
        if ($error !== null) {
            throw $error;
        }
    }
}
