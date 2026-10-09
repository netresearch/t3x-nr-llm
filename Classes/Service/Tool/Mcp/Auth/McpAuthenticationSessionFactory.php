<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\Auth;

use Netresearch\NrLlm\Domain\Enum\McpAuthenticationMode;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrLlm\Service\Tool\Mcp\McpClockInterface;
use Netresearch\NrLlm\Service\Tool\Mcp\McpOperationDeadline;
use Netresearch\NrVault\Http\CancellationSignalInterface;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * @internal Construction binds identity before a credential can reach MCP.
 */
final readonly class McpAuthenticationSessionFactory implements McpAuthenticationSessionFactoryInterface
{
    private McpDelegationConfiguration $configuration;

    private McpTokenExchange $exchange;

    public function __construct(
        ExtensionConfiguration $configuration,
        private McpSubjectResolverInterface $resolver,
        private VaultServiceInterface $vault,
        private SecureHttpClientFactory $httpClientFactory,
        private McpClockInterface $clock,
    ) {
        $this->configuration = new McpDelegationConfiguration($configuration);
        $this->exchange = new McpTokenExchange($vault, $httpClientFactory, $clock);
    }

    public function openForExecution(
        McpServerRecord $server,
        ?AiActorContext $actor,
        McpOperationDeadline $deadline,
        ?CancellationSignalInterface $cancellation = null,
    ): ?McpCredentialSessionInterface {
        $mode = $this->mode($server);
        if ($mode === McpAuthenticationMode::LEGACY) {
            return null;
        }

        try {
            McpAuthOperationGuard::assertAlive($server->identifier, $deadline, $cancellation);
            if (!$actor instanceof AiActorContext || !$actor->isAuthenticated()) {
                throw McpTransportException::forDelegatedAuthFailure($server->identifier, 'actor_unavailable');
            }

            $profile = $this->configuration->profile($server->delegationProfile);
            $scopes = McpAuthValidation::scopeString($server->delegationScopes);
            McpAuthValidation::grant(
                $server->delegationAudience,
                $scopes,
                $profile->allowedAudiences,
                $profile->allowedScopes,
            );
            $this->exchange->assertHosts($server, $profile);
            $subject = $this->resolver->resolve($actor, $profile, $server->delegationAudience, $scopes);
            McpAuthValidation::grant(
                $server->delegationAudience,
                $scopes,
                $subject->allowedAudiences,
                $subject->allowedScopes,
            );
            return new McpDelegatedCredentialSession(
                new McpDelegationBinding($server, $actor, $profile, $scopes, $deadline, $cancellation),
                $this->resolver,
                $this->exchange,
                $this->vault,
                $this->clock,
                $subject,
            );
        } catch (McpTransportException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw McpTransportException::forDelegatedAuthFailure($server->identifier, 'configuration_failed');
        }
    }

    public function openForDiscovery(
        McpServerRecord $server,
        McpOperationDeadline $deadline,
        ?CancellationSignalInterface $cancellation = null,
    ): ?McpCredentialSessionInterface {
        $mode = $this->mode($server);
        if ($mode === McpAuthenticationMode::LEGACY) {
            return null;
        }

        try {
            McpAuthOperationGuard::assertAlive($server->identifier, $deadline, $cancellation);
            McpAuthValidation::credentialIdentifier($server->discoveryCredential);
            McpAuthValidation::endpoint($server->url);
            $host = parse_url($server->url, PHP_URL_HOST);
            if (!is_string($host) || !$this->httpClientFactory->isHostAllowed($host)) {
                throw McpTransportException::forDelegatedAuthFailure($server->identifier, 'host_refused');
            }

            return new McpDiscoveryCredentialSession(
                $server->identifier,
                $server->discoveryCredential,
                $deadline,
                $cancellation,
            );
        } catch (McpTransportException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw McpTransportException::forDelegatedAuthFailure($server->identifier, 'discovery_unavailable');
        }
    }

    private function mode(McpServerRecord $server): McpAuthenticationMode
    {
        $mode = $server->authenticationMode();
        if (!$mode instanceof McpAuthenticationMode) {
            throw McpTransportException::forDelegatedAuthFailure($server->identifier, 'mode_unavailable');
        }

        return $mode;
    }
}
