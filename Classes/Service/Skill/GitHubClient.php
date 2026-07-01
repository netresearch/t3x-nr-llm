<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use JsonException;
use Netresearch\NrLlm\Service\Skill\Exception\GitHubApiException;
use Netresearch\NrLlm\Service\Skill\Exception\HostNotAllowedException;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Minimal GitHub fetch client for skill ingestion.
 *
 * SECURITY: the host allowlist (self::ALLOWED_HOSTS) is enforced on the INITIAL request URL only.
 * Its safety therefore depends on the transport NOT following redirects: any 3xx response is treated
 * as an error (see getBody()), because a followed redirect could escape the allowlist to an arbitrary
 * host. The production transport (nr-vault audited client) has redirects disabled by factory default;
 * an injected test client (setHttpClient) must likewise not follow redirects.
 */
final class GitHubClient implements GitHubClientInterface
{
    private const ALLOWED_HOSTS = ['github.com', 'raw.githubusercontent.com', 'api.github.com', 'codeload.github.com'];

    private ?ClientInterface $httpClient = null;

    public function __construct(
        private readonly VaultServiceInterface $vault,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly LoggerInterface $logger,
    ) {}

    public function setHttpClient(ClientInterface $client): void
    {
        $this->httpClient = $client;
    }

    public function resolveSha(string $owner, string $repo, string $ref, ?string $tokenUuid): string
    {
        // Encode the ref per path-segment (like fetchRawBySha encodes paths) so slash refs such as
        // "release/1.0" resolve; encoding the whole ref would turn the separating slash into %2F.
        $refEncoded = implode('/', array_map(rawurlencode(...), explode('/', $ref)));
        $url = sprintf('https://api.github.com/repos/%s/%s/commits/%s', rawurlencode($owner), rawurlencode($repo), $refEncoded);
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
        // A 200 response with a missing/non-array `tree` is malformed, not an
        // empty repo: defaulting to [] here would make the sync conclude every
        // skill was deleted upstream and orphan (delete) them all. Fail loudly.
        if (!isset($data['tree']) || !is_array($data['tree'])) {
            throw GitHubApiException::forMalformedResponse($url);
        }
        $tree = $data['tree'];
        $paths = [];
        foreach ($tree as $node) {
            if (is_array($node) && ($node['type'] ?? '') === 'blob' && isset($node['path']) && is_string($node['path'])) {
                $paths[] = $node['path'];
            }
        }
        return $paths;
    }

    public function fetchRawBySha(string $owner, string $repo, string $sha, string $path, ?string $tokenUuid): string
    {
        $segments = implode('/', array_map(rawurlencode(...), explode('/', $path)));
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
        $body = $this->getBody($url, $tokenUuid);
        // A malformed/garbled JSON body is a distinct failure from a transport status
        // (e.g. a 404): surface it as such with a body sample logged, instead of the
        // misleading "failed with status 0" a generic forStatus() would produce.
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->warning('GitHub API response was not valid JSON', [
                'url' => $url,
                'message' => $e->getMessage(),
                'sample' => substr($body, 0, 200),
            ]);

            throw GitHubApiException::forMalformedResponse($url);
        }
        if (!is_array($decoded)) {
            $this->logger->warning('GitHub API response was not a JSON object', [
                'url' => $url,
                'sample' => substr($body, 0, 200),
            ]);

            throw GitHubApiException::forMalformedResponse($url);
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
        if ($this->isRateLimited($status, $response)) {
            // Rate-limit failures must abort the whole sync (re-thrown past per-file/per-repo isolation).
            throw GitHubApiException::forRateLimit((int)$response->getHeaderLine('X-RateLimit-Reset'));
        }
        if ($status >= 300) {
            // 3xx included: redirects are not followed (could escape the GitHub allowlist).
            throw GitHubApiException::forStatus($url, $status);
        }
        return (string)$response->getBody();
    }

    /**
     * Detect a rate-limit response: HTTP 429, or a 403 that carries either a Retry-After header
     * or an exhausted primary rate-limit budget (X-RateLimit-Remaining: 0).
     */
    private function isRateLimited(int $status, ResponseInterface $response): bool
    {
        if ($status === 429) {
            return true;
        }
        if ($status === 403) {
            return $response->getHeaderLine('X-RateLimit-Remaining') === '0'
                || $response->hasHeader('Retry-After');
        }
        return false;
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
