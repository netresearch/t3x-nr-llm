<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\Auth;

use InvalidArgumentException;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * @internal Explicit operator identity mappings; administrator status grants no identity.
 */
final readonly class ConfiguredMcpSubjectResolver implements McpSubjectResolverInterface
{
    private McpDelegationConfiguration $configuration;

    public function __construct(ExtensionConfiguration $configuration)
    {
        $this->configuration = new McpDelegationConfiguration($configuration);
    }

    /**
     * @param list<string> $scopes
     */
    public function resolve(
        AiActorContext $actor,
        McpDelegationProfile $profile,
        string $audience,
        array $scopes,
    ): McpSubjectCredential {
        $definition = $this->configuration->definition($profile->identifier);
        $entry = $this->permittedActorEntry($definition, $actor, $audience, $scopes);
        if (($entry['enabled'] ?? false) !== true) {
            throw new InvalidArgumentException('Delegation identity mapping is unavailable.', 4172477858);
        }

        $subject = new McpSubjectCredential(
            McpDelegationConfiguration::stringValue($entry, 'credentialIdentifier'),
            McpDelegationConfiguration::stringList($entry, 'allowedAudiences'),
            McpDelegationConfiguration::stringList($entry, 'allowedScopes'),
        );
        McpAuthValidation::grant($audience, $scopes, $profile->allowedAudiences, $profile->allowedScopes);
        McpAuthValidation::grant($audience, $scopes, $subject->allowedAudiences, $subject->allowedScopes);
        return $subject;
    }

    /**
     * @param array<array-key,mixed> $definition
     *
     * @return array<array-key,mixed>
     */
    private function actorEntry(array $definition, AiActorContext $actor): array
    {
        if (!$actor->isAuthenticated()) {
            throw new InvalidArgumentException('Delegation requires an explicit authenticated actor.', 2324035418);
        }

        $entries = $definition[$actor->isServiceAccount() ? 'serviceAccounts' : 'backendUsers'] ?? null;
        $key = $actor->serviceAccount ?? $actor->backendUserUid;
        $entry = is_array($entries) ? $entries[$key] ?? null : null;
        if (!is_array($entry)) {
            throw new InvalidArgumentException('Delegation identity mapping is unavailable.', 9984912023);
        }

        return $entry;
    }

    /**
     * @param array<array-key,mixed> $definition
     * @param list<string>           $scopes
     *
     * @return array<array-key,mixed>
     */
    private function permittedActorEntry(
        array $definition,
        AiActorContext $actor,
        string $audience,
        array $scopes,
    ): array {
        McpAuthValidation::grant(
            $audience,
            $scopes,
            McpAuthValidation::audiences(McpDelegationConfiguration::stringList($definition, 'allowedAudiences')),
            McpAuthValidation::scopes(McpDelegationConfiguration::stringList($definition, 'allowedScopes')),
        );
        return $this->actorEntry($definition, $actor);
    }
}
