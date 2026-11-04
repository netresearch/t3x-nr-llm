<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\OAuth;

/**
 * One authorization in flight: what the callback needs to finish it (ADR-259).
 *
 * MCP "Authorization Response Validation" asks for the expected issuer to be
 * kept in the same per-request record as the PKCE verifier and the state; this
 * is that record. It lives in the administrator's backend session, keyed by
 * the state, and is removed when the callback reads it.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class McpOAuthPendingAuthorization
{
    public const LIFETIME_SECONDS = 600;

    public function __construct(
        public int $serverUid,
        public string $state,
        public string $verifier,
        public string $issuer,
        public bool $issParameterSupported,
        public string $tokenEndpoint,
        public string $resource,
        public string $redirectUri,
        public string $clientId,
        public string $clientSecretCredential,
        public string $clientSource,
        // True when this flow registered the client, so an abandoned or failed
        // flow removes the registration's secret again.
        public bool $clientCreated,
        public string $scope,
        public int $createdAt,
    ) {}

    public function isExpired(int $now): bool
    {
        return $now - $this->createdAt > self::LIFETIME_SECONDS || $now < $this->createdAt;
    }

    /**
     * @return array<string, bool|int|string>
     */
    public function toArray(): array
    {
        return [
            'serverUid'              => $this->serverUid,
            'state'                  => $this->state,
            'verifier'               => $this->verifier,
            'issuer'                 => $this->issuer,
            'issParameterSupported'  => $this->issParameterSupported,
            'tokenEndpoint'          => $this->tokenEndpoint,
            'resource'               => $this->resource,
            'redirectUri'            => $this->redirectUri,
            'clientId'               => $this->clientId,
            'clientSecretCredential' => $this->clientSecretCredential,
            'clientSource'           => $this->clientSource,
            'clientCreated'          => $this->clientCreated,
            'scope'                  => $this->scope,
            'createdAt'              => $this->createdAt,
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $strings = ['state', 'verifier', 'issuer', 'tokenEndpoint', 'resource', 'redirectUri', 'clientId', 'clientSecretCredential', 'clientSource', 'scope'];
        foreach ($strings as $key) {
            if (!is_string($data[$key] ?? null)) {
                return null;
            }
        }

        if (!is_int($data['serverUid'] ?? null) || !is_int($data['createdAt'] ?? null) || !is_bool($data['issParameterSupported'] ?? null) || !is_bool($data['clientCreated'] ?? null)) {
            return null;
        }

        /** @var array{serverUid:int,state:string,verifier:string,issuer:string,issParameterSupported:bool,tokenEndpoint:string,resource:string,redirectUri:string,clientId:string,clientSecretCredential:string,clientSource:string,clientCreated:bool,scope:string,createdAt:int} $data */
        return new self(
            $data['serverUid'],
            $data['state'],
            $data['verifier'],
            $data['issuer'],
            $data['issParameterSupported'],
            $data['tokenEndpoint'],
            $data['resource'],
            $data['redirectUri'],
            $data['clientId'],
            $data['clientSecretCredential'],
            $data['clientSource'],
            $data['clientCreated'],
            $data['scope'],
            $data['createdAt'],
        );
    }
}
