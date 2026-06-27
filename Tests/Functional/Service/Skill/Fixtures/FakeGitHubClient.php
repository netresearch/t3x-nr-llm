<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Skill\Fixtures;

use Netresearch\NrLlm\Service\Skill\GitHubClientInterface;
use Psr\Http\Client\ClientInterface;

final class FakeGitHubClient implements GitHubClientInterface
{
    /**
     * @param list<string>         $tree
     * @param array<string,string> $bodies
     */
    public function __construct(
        private readonly string $sha,
        private readonly array $tree,
        private readonly array $bodies,
    ) {}

    public function resolveSha(string $owner, string $repo, string $ref, ?string $tokenUuid): string
    {
        return $this->sha;
    }

    public function listTree(string $owner, string $repo, string $sha, ?string $tokenUuid): array
    {
        return $this->tree;
    }

    public function fetchRawBySha(string $owner, string $repo, string $sha, string $path, ?string $tokenUuid): string
    {
        return $this->bodies[$path] ?? '';
    }

    public function fetchAllowedUrl(string $url, ?string $tokenUuid): string
    {
        return '';
    }

    public function setHttpClient(ClientInterface $client): void {}
}
