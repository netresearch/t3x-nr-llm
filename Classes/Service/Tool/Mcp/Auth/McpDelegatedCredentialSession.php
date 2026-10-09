<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\Auth;

use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrLlm\Service\Tool\Mcp\McpClockInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Throwable;

/**
 * @internal One operation, one actor and one server; no shared credential cache.
 */
final class McpDelegatedCredentialSession implements McpCredentialSessionInterface
{
    private bool $closed = false;

    private ?McpIssuedCredential $issued = null;

    public function __construct(
        private readonly McpDelegationBinding $binding,
        private readonly McpSubjectResolverInterface $resolver,
        private readonly McpTokenExchange $exchange,
        private readonly VaultServiceInterface $vault,
        private readonly McpClockInterface $clock,
        private ?McpSubjectCredential $initialSubject,
    ) {}

    public function credentialIdentifier(): string
    {
        if ($this->closed) {
            throw McpTransportException::forDelegatedAuthFailure($this->binding->server->identifier, 'session_closed');
        }

        McpAuthOperationGuard::assertAlive(
            $this->binding->server->identifier,
            $this->binding->deadline,
            $this->binding->cancellation,
        );
        try {
            if (!$this->issued instanceof McpIssuedCredential || $this->clock->monotonicNanoseconds() >= $this->issued->expiresAtNanoseconds) {
                $this->renew();
            }

            if (!$this->issued instanceof McpIssuedCredential) {
                throw McpTransportException::forDelegatedAuthFailure(
                    $this->binding->server->identifier,
                    'credential_unavailable',
                );
            }

            return $this->issued->identifier;
        } catch (McpTransportException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw McpTransportException::forDelegatedAuthFailure($this->binding->server->identifier, 'credential_failed');
        }
    }

    public function close(): void
    {
        $this->closed = true;
        try {
            $this->removeIssued();
        } catch (Throwable) {
            throw McpTransportException::forDelegatedAuthFailure($this->binding->server->identifier, 'cleanup_failed');
        }
    }

    private function renew(): void
    {
        $subject = $this->initialSubject ?? $this->resolver->resolve(
            $this->binding->actor,
            $this->binding->profile,
            $this->binding->server->delegationAudience,
            $this->binding->scopes,
        );
        $this->initialSubject = null;
        McpAuthValidation::grant(
            $this->binding->server->delegationAudience,
            $this->binding->scopes,
            $this->binding->profile->allowedAudiences,
            $this->binding->profile->allowedScopes,
        );
        McpAuthValidation::grant(
            $this->binding->server->delegationAudience,
            $this->binding->scopes,
            $subject->allowedAudiences,
            $subject->allowedScopes,
        );
        $this->removeIssued();
        $this->issued = $this->exchange->exchange(
            $this->binding->server,
            $this->binding->actor,
            $this->binding->profile,
            $subject,
            $this->binding->scopes,
            $this->binding->deadline,
            $this->binding->cancellation,
        );
    }

    private function removeIssued(): void
    {
        if ($this->issued instanceof McpIssuedCredential) {
            $this->vault->delete($this->issued->identifier, 'MCP delegated credential cleanup');
            $this->issued = null;
        }
    }
}
