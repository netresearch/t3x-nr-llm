<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use Netresearch\NrLlm\Service\Skill\Exception\GitHubApiException;
use Netresearch\NrLlm\Service\Skill\Exception\HostNotAllowedException;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class GitHubClient implements GitHubClientInterface
{
    private const ALLOWED_HOSTS = ['github.com', 'raw.githubusercontent.com', 'api.github.com', 'codeload.github.com'];

    private ?ClientInterface $httpClient = null;

    public function __construct(
        private readonly VaultServiceInterface $vault,
        private readonly RequestFactoryInterface $requestFactory,
    ) {}

    public function setHttpClient(ClientInterface $client): void
    {
        $this->httpClient = $client;
    }

    public function resolveSha(string $owner, string $repo, string $ref, ?string $tokenUuid): string
    {
        $url = sprintf('https://api.github.com/repos/%s/%s/commits/%s', rawurlencode($owner), rawurlencode($repo), rawurlencode($ref));
        $data = $this->getJson($url, $tokenUuid);
        if (!isset($data['sha']) || !is_string($data['sha'])) {
            throw GitHubApiException::forStatus($url, 0);
        }
        return $data['sha'];
    }

    /**
     * @return list<string>
     */
    public function listTree(string $owner, string $repo, string $sha, ?string $tokenUuid): array
    {
        $url = sprintf('https://api.github.com/repos/%s/%s/git/trees/%s?recursive=1', rawurlencode($owner), rawurlencode($repo), rawurlencode($sha));
        $data = $this->getJson($url, $tokenUuid);
        $paths = [];
        foreach ((array)($data['tree'] ?? []) as $node) {
            if (is_array($node) && ($node['type'] ?? '') === 'blob' && isset($node['path']) && is_string($node['path'])) {
                $paths[] = $node['path'];
            }
        }
        return $paths;
    }

    public function fetchRawBySha(string $owner, string $repo, string $sha, string $path, ?string $tokenUuid): string
    {
        $segments = implode('/', array_map('rawurlencode', explode('/', $path)));
        $url = sprintf('https://raw.githubusercontent.com/%s/%s/%s/%s', rawurlencode($owner), rawurlencode($repo), rawurlencode($sha), $segments);
        return $this->getBody($url, $tokenUuid);
    }

    public function fetchAllowedUrl(string $url, ?string $tokenUuid): string
    {
        return $this->getBody($url, $tokenUuid);
    }

    /**
     * @return array<string,mixed>
     */
    private function getJson(string $url, ?string $tokenUuid): array
    {
        $decoded = json_decode($this->getBody($url, $tokenUuid), true);
        if (!is_array($decoded)) {
            throw GitHubApiException::forStatus($url, 0);
        }
        /** @var array<string,mixed> $decoded */
        return $decoded;
    }

    private function getBody(string $url, ?string $tokenUuid): string
    {
        $this->assertAllowed($url);
        $request = $this->requestFactory->createRequest('GET', $url)
            ->withHeader('Accept', 'application/vnd.github+json')
            ->withHeader('User-Agent', 'nr-llm-skills');
        if ($tokenUuid !== null && $tokenUuid !== '') {
            $token = $this->vault->retrieve($tokenUuid) ?? '';
            if ($token !== '') {
                $request = $request->withHeader('Authorization', 'Bearer ' . $token);
            }
        }

        $response = $this->send($request);
        $status = $response->getStatusCode();
        if ($status === 403 && $response->getHeaderLine('X-RateLimit-Remaining') === '0') {
            throw GitHubApiException::forRateLimit((int)$response->getHeaderLine('X-RateLimit-Reset'));
        }
        if ($status >= 300) {
            // 3xx included: redirects are not followed (could escape the GitHub allowlist).
            throw GitHubApiException::forStatus($url, $status);
        }
        return (string)$response->getBody();
    }

    private function send(RequestInterface $request): ResponseInterface
    {
        if ($this->httpClient !== null) {
            return $this->httpClient->sendRequest($request);
        }
        // Production transport: nr-vault audited client (redirects disabled at factory default).
        return $this->vault->http()->withReason('nr-llm skill sync')->sendRequest($request);
    }

    private function assertAllowed(string $url): void
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        if ($scheme !== 'https' || !in_array($host, self::ALLOWED_HOSTS, true)) {
            throw HostNotAllowedException::forUrl($url);
        }
    }
}
