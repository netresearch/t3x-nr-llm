<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\Auth;

use GuzzleHttp\Psr7\Request;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrLlm\Service\Tool\Mcp\McpClockInterface;
use Netresearch\NrLlm\Service\Tool\Mcp\McpOperationDeadline;
use Netresearch\NrVault\Exception\RequestCancelledException;
use Netresearch\NrVault\Http\CancellableHttpClientInterface;
use Netresearch\NrVault\Http\CancellationSignalInterface;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * @internal RFC-8693 exchange through Vault's injection and transport boundary.
 */
final readonly class McpTokenExchange
{
    private const TOKEN_TYPE = 'urn:ietf:params:oauth:token-type:access_token';

    private const MAX_RESPONSE_BYTES = 65536;

    private const MAX_TOKEN_BYTES = 8192;

    private const MAX_LIFETIME_SECONDS = 300;

    public function __construct(
        private VaultServiceInterface $vault,
        private SecureHttpClientFactory $httpClientFactory,
        private McpClockInterface $clock,
    ) {}

    /**
     * @param list<string> $scopes
     */
    public function exchange(
        McpServerRecord $server,
        AiActorContext $actor,
        McpDelegationProfile $profile,
        McpSubjectCredential $subject,
        array $scopes,
        McpOperationDeadline $deadline,
        ?CancellationSignalInterface $cancellation,
    ): McpIssuedCredential {
        $body = '';
        $payload = [];
        $response = null;
        try {
            McpAuthOperationGuard::assertAlive($server->identifier, $deadline, $cancellation);
            $this->assertHosts($server, $profile);
            $client = $this->client($profile, $subject, $deadline->legTimeoutSeconds());
            $request = new Request(
                'POST',
                $profile->tokenEndpoint,
                ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
                http_build_query(
                    [
                        'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
                        'subject_token_type' => self::TOKEN_TYPE,
                        'requested_token_type' => self::TOKEN_TYPE,
                        'client_id' => $profile->clientId,
                        'audience' => $server->delegationAudience,
                        'scope' => implode(' ', $scopes),
                    ],
                ),
            );
            $started = $this->clock->monotonicNanoseconds();
            $response = $client instanceof CancellableHttpClientInterface && $cancellation instanceof CancellationSignalInterface && $client->supportsCancellation() ? $client->sendCancellable($request, $cancellation) : $client->sendRequest($request);
            $body = $this->readResponse($response);
            $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new InvalidArgumentException('Invalid token response.', 3748556769);
            }

            $this->validateResponse($payload, $scopes, $server->delegationAudience);
            McpAuthOperationGuard::assertAlive($server->identifier, $deadline, $cancellation);
            return $this->verifiedCredential(
                $this->storeResponse(
                    $payload,
                    $server,
                    $actor,
                    $profile,
                    $scopes,
                    $started,
                ),
                $server,
                $deadline,
                $cancellation,
            );
        } catch (McpTransportException $exception) {
            throw $exception;
        } catch (RequestCancelledException) {
            throw McpTransportException::forCancelledCall($server->identifier);
        } catch (Throwable) {
            throw McpTransportException::forDelegatedAuthFailure($server->identifier, 'exchange_failed');
        } finally {
            $this->clearResponse($response, $body, $payload);
        }
    }

    public function assertHosts(McpServerRecord $server, McpDelegationProfile $profile): void
    {
        McpAuthValidation::endpoint($server->url);
        foreach ([$server->url, $profile->tokenEndpoint] as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            if (!is_string($host) || !$this->httpClientFactory->isHostAllowed($host)) {
                throw new InvalidArgumentException('Delegation host is refused.', 2879157980);
            }
        }
    }

    private function client(
        McpDelegationProfile $profile,
        McpSubjectCredential $subject,
        int $timeout,
    ): VaultHttpClientInterface {
        $client = $this->vault->http()->withTimeout($timeout)->withReason('MCP delegated token exchange');
        if ($profile->clientSecretIdentifier === null) {
            return $client->withAuthentication(
                $subject->credentialIdentifier,
                SecretPlacement::BodyField,
                ['bodyField' => 'subject_token'],
            );
        }

        if (!interface_exists($this->capabilityName()) || !method_exists($client, 'withAdditionalBodyField') || !is_a($client, $this->capabilityName())) {
            throw new InvalidArgumentException('Installed Vault lacks additional body credentials.', 8707031728);
        }

        $client = $client->withAuthentication(
            $profile->clientSecretIdentifier,
            SecretPlacement::BodyField,
            ['bodyField' => 'client_secret'],
        );
        return $this->withAdditionalSubject($client, $subject);
    }

    private function readResponse(ResponseInterface $response): string
    {
        $contentType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300 || $contentType !== 'application/json') {
            throw new InvalidArgumentException('Token endpoint refused the exchange.', 8707934477);
        }

        $body = '';
        $stream = $response->getBody();
        try {
            while (!$stream->eof()) {
                $chunk = $stream->read(min(8192, self::MAX_RESPONSE_BYTES + 1 - strlen($body)));
                if ($chunk === '' || strlen($body) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    throw new InvalidArgumentException('Token response is unavailable or exceeds its bound.', 7461611613);
                }

                $body .= $chunk;
            }

            return $body;
        } finally {
            sodium_memzero($body);
        }
    }

    /**
     * @param array<array-key,mixed> $payload
     * @param list<string>           $scopes
     */
    private function validateResponse(array $payload, array $scopes, string $audience): void
    {
        $this->validateToken($payload);
        $this->lifetime($payload);
        $this->validateIssuedScopes($payload, $scopes);
        if (array_key_exists('audience', $payload) && $payload['audience'] !== $audience) {
            throw new InvalidArgumentException('Issued audience differs from the requested audience.', 7094439805);
        }
    }

    /**
     * @param array<array-key,mixed> $payload
     */
    private function lifetime(array $payload): float
    {
        $lifetime = $payload['expires_in'] ?? null;
        if (!is_int($lifetime) && !is_float($lifetime) || !is_finite((float)$lifetime) || $lifetime < 1) {
            throw new InvalidArgumentException('Token response has no usable finite lifetime.', 2776456353);
        }

        return min((float)$lifetime, self::MAX_LIFETIME_SECONDS);
    }

    /**
     * @param array<array-key,mixed> $payload
     * @param list<string>           $scopes
     */
    private function storeResponse(
        array $payload,
        McpServerRecord $server,
        AiActorContext $actor,
        McpDelegationProfile $profile,
        array $scopes,
        int $started,
    ): McpIssuedCredential {
        $expires = $started + (int)floor($this->lifetime($payload) * 1000000000);
        $remaining = (int)floor(($expires - $this->clock->monotonicNanoseconds()) / 1000000000);
        if ($remaining < 1) {
            throw new InvalidArgumentException(
                'The issued credential lifetime was already spent.',
                9803193319,
            );
        }

        $token = $payload['access_token'] ?? null;
        if (!is_string($token)) {
            throw new InvalidArgumentException('Invalid token response.', 5510676907);
        }

        $identifier = Uuid::v7()->toRfc4122();
        try {
            $this->vault->store(
                $identifier,
                $token,
                [
                    'owner' => $actor->backendUserUid,
                    'groups' => [],
                    'context' => 'mcp_delegation',
                    'expiresAt' => time() + $remaining,
                    'metadata' => [
                        'server' => $server->identifier,
                        'profile' => $profile->identifier,
                        'audience' => $server->delegationAudience,
                        'scopes' => $scopes,
                        'actorUid' => $actor->backendUserUid,
                        'serviceAccount' => $actor->serviceAccount,
                    ],
                ],
            );
            $this->assertStoredBinding($identifier, $actor);
        } finally {
            sodium_memzero($token);
        }

        return new McpIssuedCredential($identifier, $expires);
    }

    /**
     * @param array<array-key,mixed> $payload
     */
    private function validateToken(array $payload): void
    {
        $token = $payload['access_token'] ?? null;
        if (!is_string($token) || $token === '' || strlen($token) > self::MAX_TOKEN_BYTES || preg_match('/[\x00-\x20\x7F]/', $token) === 1) {
            throw new InvalidArgumentException('Token response has an invalid credential.', 1677639217);
        }

        $type = $payload['token_type'] ?? null;
        if (!is_string($type) || strcasecmp($type, 'Bearer') !== 0 || ($payload['issued_token_type'] ?? null) !== self::TOKEN_TYPE) {
            throw new InvalidArgumentException('Token response has an invalid type.', 5359848897);
        }
    }

    /**
     * @param array<array-key,mixed> $payload
     * @param list<string>           $scopes
     */
    private function validateIssuedScopes(array $payload, array $scopes): void
    {
        if (array_key_exists('scope', $payload)) {
            if (!is_string($payload['scope'])) {
                throw new InvalidArgumentException('Invalid issued scope.', 8267129100);
            }

            $issued = McpAuthValidation::scopeString($payload['scope']);
            if (array_diff($issued, $scopes) !== []) {
                throw new InvalidArgumentException('Issued scopes exceed requested scopes.', 4413070275);
            }
        }
    }

    /**
     * @param-out null $body
     */
    private function clearResponse(?ResponseInterface $response, string &$body, mixed &$payload): void
    {
        sodium_memzero($body);
        if (is_array($payload) && isset($payload['access_token']) && is_string($payload['access_token'])) {
            sodium_memzero($payload['access_token']);
        }

        if ($response instanceof ResponseInterface) {
            try {
                $response->getBody()->close();
            } catch (Throwable) {
                // Best-effort disposal must not replace a sanitized outcome with
                // a stream exception that could contain the token response body.
            }
        }
    }

    private function withAdditionalSubject(
        VaultHttpClientInterface $client,
        McpSubjectCredential $subject,
    ): VaultHttpClientInterface {
        if (!method_exists($client, 'withAdditionalBodyField')) {
            throw new InvalidArgumentException('Vault authentication clone lost its capability.', 1082481014);
        }

        $configured = $client->withAdditionalBodyField($subject->credentialIdentifier, 'subject_token');
        if (!$configured instanceof VaultHttpClientInterface) {
            throw new InvalidArgumentException('Vault returned an invalid credential client.', 6526029082);
        }

        return $configured;
    }

    /**
     * Keep optional SDK capability detection independent from installed SDK types.
     */
    private function capabilityName(): string
    {
        return implode('\\', ['Netresearch', 'NrVault', 'Http', 'AdditionalSecretHttpClientInterface']);
    }

    private function assertStoredBinding(
        string $identifier,
        AiActorContext $actor,
    ): void {
        try {
            $details = $this->vault->getMetadata($identifier);
            if ($details->ownerUid !== $actor->backendUserUid || $details->groups !== [] || $details->frontendAccessible) {
                throw new InvalidArgumentException(
                    'Stored credential binding differs from the initiating actor.',
                    2880924673,
                );
            }
        } catch (Throwable $exception) {
            $this->vault->delete(
                $identifier,
                'Discard invalid MCP delegated credential binding',
            );
            throw $exception;
        }
    }

    private function verifiedCredential(
        McpIssuedCredential $credential,
        McpServerRecord $server,
        McpOperationDeadline $deadline,
        ?CancellationSignalInterface $cancellation,
    ): McpIssuedCredential {
        try {
            McpAuthOperationGuard::assertAlive(
                $server->identifier,
                $deadline,
                $cancellation,
            );
            if ($this->clock->monotonicNanoseconds() >= $credential->expiresAtNanoseconds) {
                throw new InvalidArgumentException(
                    'Credential expired during Vault persistence.',
                    1374592913,
                );
            }
        } catch (Throwable $exception) {
            $this->vault->delete(
                $credential->identifier,
                'Discard unusable MCP delegated credential',
            );
            throw $exception;
        }

        return $credential;
    }
}
