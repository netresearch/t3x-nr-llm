<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\OAuth;

use GuzzleHttp\Psr7\Request;
use JsonException;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Every request of the OAuth flow, through the Vault HTTP client (ADR-259).
 *
 * Three rules hold for all of them, and they are the reason this class exists
 * rather than a second HTTP client:
 *
 * - The URL is `https`, carries no user information and no fragment, and its
 *   host passes the Vault host gate before anything is sent. Protected
 *   resource metadata can name any authorization server, so this is what keeps
 *   a hostile MCP server from steering requests into the internal network.
 * - A secret in a form body is injected by Vault (body-field placement), so the
 *   refresh token and a client secret never exist in this process.
 * - Redirects are not followed (the Vault client pins that off) and a body is
 *   read to a fixed bound.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class McpOAuthHttp
{
    public const MAX_BODY_BYTES = 65536;

    private const JSON_DEPTH = 16;

    public function __construct(
        private VaultServiceInterface $vault,
        private SecureHttpClientFactory $httpClientFactory,
    ) {}

    /**
     * Refuse a URL this flow may not contact.
     *
     * @throws McpOAuthException
     */
    public function assertUrl(string $url, string $reason = 'endpoint_refused'): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7F]/', $url) === 1
        ) {
            throw McpOAuthException::because($reason);
        }

        if (!$this->httpClientFactory->isHostAllowed($parts['host'])) {
            throw McpOAuthException::because('host_refused');
        }
    }

    /**
     * @throws McpOAuthException
     */
    public function get(string $url, int $timeoutSeconds): McpOAuthHttpResponse
    {
        $this->assertUrl($url);

        return $this->send(
            $this->client($timeoutSeconds),
            new Request('GET', $url, ['Accept' => 'application/json']),
        );
    }

    /**
     * POST a JSON body (the unauthenticated MCP probe, client registration).
     *
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers
     *
     * @throws McpOAuthException
     */
    public function postJson(string $url, array $payload, int $timeoutSeconds, array $headers = []): McpOAuthHttpResponse
    {
        $this->assertUrl($url);

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw McpOAuthException::because('request_unencodable');
        }

        return $this->send(
            $this->client($timeoutSeconds),
            new Request('POST', $url, ['Content-Type' => 'application/json', 'Accept' => 'application/json'] + $headers, $body),
        );
    }

    /**
     * POST a form to a token endpoint.
     *
     * `$secretFields` maps a form field to the Vault identifier whose value
     * Vault writes into it; at most two, which is what a refresh by a
     * confidential client needs (refresh token and client secret).
     *
     * @param array<string, string> $fields
     * @param array<string, string> $secretFields
     *
     * @throws McpOAuthException
     */
    public function postForm(string $url, array $fields, array $secretFields, int $timeoutSeconds): McpOAuthHttpResponse
    {
        $this->assertUrl($url);

        return $this->send(
            $this->withSecrets($this->client($timeoutSeconds), $secretFields),
            new Request(
                'POST',
                $url,
                ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
                http_build_query($fields, '', '&', PHP_QUERY_RFC1738),
            ),
        );
    }

    /**
     * A JSON object, or a refusal. Arrays at the top level are not metadata.
     *
     * @return array<string, mixed>
     *
     * @throws McpOAuthException
     */
    public static function jsonObject(McpOAuthHttpResponse $response, string $reason): array
    {
        if ($response->mediaType() !== 'application/json') {
            throw McpOAuthException::because($reason);
        }

        try {
            $decoded = json_decode($response->body, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw McpOAuthException::because($reason);
        }

        if (!is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
            throw McpOAuthException::because($reason);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function client(int $timeoutSeconds): VaultHttpClientInterface
    {
        return $this->vault
            ->http()
            ->withReason('nr-llm MCP OAuth')
            ->withTimeout(max(1, $timeoutSeconds));
    }

    /**
     * @param array<string, string> $secretFields
     *
     * @throws McpOAuthException
     */
    private function withSecrets(VaultHttpClientInterface $client, array $secretFields): VaultHttpClientInterface
    {
        if ($secretFields === []) {
            return $client;
        }

        if (count($secretFields) > 2) {
            throw McpOAuthException::because('request_unencodable');
        }

        $field  = array_key_first($secretFields);
        $client = $client->withAuthentication($secretFields[$field], SecretPlacement::BodyField, ['bodyField' => $field]);
        unset($secretFields[$field]);
        if ($secretFields === []) {
            return $client;
        }

        // The same optional capability ADR-217 detects for a confidential
        // token exchange: two Vault secrets in one form body.
        if (!method_exists($client, 'withAdditionalBodyField')) {
            throw McpOAuthException::because('vault_capability_missing');
        }

        $second     = array_key_first($secretFields);
        $configured = $client->withAdditionalBodyField($secretFields[$second], $second);
        if (!$configured instanceof VaultHttpClientInterface) {
            throw McpOAuthException::because('vault_capability_missing');
        }

        return $configured;
    }

    /**
     * @throws McpOAuthException
     */
    private function send(VaultHttpClientInterface $client, Request $request): McpOAuthHttpResponse
    {
        $response = null;
        try {
            $response = $client->sendRequest($request);

            return new McpOAuthHttpResponse(
                $response->getStatusCode(),
                $response->getHeaderLine('Content-Type'),
                $response->getHeaderLine('WWW-Authenticate'),
                $this->readBounded($response),
            );
        } catch (McpOAuthException $exception) {
            throw $exception;
        } catch (Throwable) {
            // The transport's message can carry a URL with a query or the far
            // side's text; neither belongs in a backend message.
            throw McpOAuthException::because('unreachable');
        } finally {
            if ($response instanceof ResponseInterface) {
                try {
                    $response->getBody()->close();
                } catch (Throwable) {
                    // Best-effort disposal; the outcome above stands.
                }
            }
        }
    }

    /**
     * @throws McpOAuthException
     */
    private function readBounded(ResponseInterface $response): string
    {
        $stream = $response->getBody();
        $body   = '';
        while (!$stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                break;
            }

            $body .= $chunk;
            if (strlen($body) > self::MAX_BODY_BYTES) {
                throw McpOAuthException::because('response_too_large');
            }
        }

        return $body;
    }
}
