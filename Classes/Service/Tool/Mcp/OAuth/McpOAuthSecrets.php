<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\OAuth;

use Netresearch\NrVault\Service\VaultServiceInterface;
use SensitiveParameter;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * The Vault side of an OAuth connection (ADR-259).
 *
 * A new secret is owned by the administrator who connected and carries no
 * groups — the same ownership a TCA vault field gives the `legacy` credential
 * it stores, so reading the token at call time follows the same rules.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class McpOAuthSecrets
{
    public const CONTEXT = 'mcp_oauth';

    public function __construct(private VaultServiceInterface $vault) {}

    /**
     * @throws McpOAuthException
     */
    public function store(
        #[SensitiveParameter]
        string $value,
        int $owner,
        string $server,
        string $kind,
    ): string {
        $identifier = Uuid::v7()->toRfc4122();
        try {
            $this->vault->store($identifier, $value, [
                'owner'    => $owner,
                'groups'   => [],
                'context'  => self::CONTEXT,
                'metadata' => ['server' => $server, 'kind' => $kind],
            ]);
        } catch (Throwable) {
            // Vault's message may quote the identifier or its own policy; the
            // value itself never reaches it, but neither belongs in the module.
            throw McpOAuthException::because('vault_store_failed');
        }

        return $identifier;
    }

    /**
     * @throws McpOAuthException
     */
    public function rotate(
        string $identifier,
        #[SensitiveParameter]
        string $value,
    ): void {
        try {
            $this->vault->rotate($identifier, $value, 'MCP OAuth token refresh');
        } catch (Throwable) {
            throw McpOAuthException::because('vault_rotate_failed');
        }
    }

    /**
     * Best-effort removal. A secret that cannot be deleted is orphaned, not
     * exposed: nothing references it once the grant row moved on.
     */
    public function forget(string ...$identifiers): void
    {
        foreach ($identifiers as $identifier) {
            if ($identifier === '') {
                continue;
            }

            try {
                $this->vault->delete($identifier, 'MCP OAuth connection replaced or removed');
            } catch (Throwable) {
                // See above.
            }
        }
    }
}
