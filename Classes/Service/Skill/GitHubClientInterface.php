<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use Netresearch\NrLlm\Service\Skill\Exception\GitHubApiException;
use Netresearch\NrLlm\Service\Skill\Exception\HostNotAllowedException;
use Psr\Http\Client\ClientInterface;

interface GitHubClientInterface
{
    /**
     * Resolve a ref (branch, tag, sha) to an immutable commit SHA.
     *
     * @throws HostNotAllowedException
     * @throws GitHubApiException
     */
    public function resolveSha(string $owner, string $repo, string $ref, ?string $tokenUuid): string;

    /**
     * List the blob paths of a repository tree at the given immutable SHA.
     *
     *
     * @throws HostNotAllowedException
     * @throws GitHubApiException
     *
     * @return list<string>
     */
    public function listTree(string $owner, string $repo, string $sha, ?string $tokenUuid): array;

    /**
     * Fetch a raw file body pinned to an immutable commit SHA.
     *
     * @throws HostNotAllowedException
     * @throws GitHubApiException
     */
    public function fetchRawBySha(string $owner, string $repo, string $sha, string $path, ?string $tokenUuid): string;

    /**
     * Fetch the body of an arbitrary URL after enforcing the GitHub host allowlist.
     *
     * @throws HostNotAllowedException
     * @throws GitHubApiException
     */
    public function fetchAllowedUrl(string $url, ?string $tokenUuid): string;

    /**
     * Inject a PSR-18 client, bypassing the production nr-vault transport (test seam).
     */
    public function setHttpClient(ClientInterface $client): void;
}
