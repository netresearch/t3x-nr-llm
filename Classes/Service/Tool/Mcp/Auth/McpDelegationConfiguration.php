<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\Auth;

use InvalidArgumentException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * @internal Read explicit configuration without coercing malformed permissions.
 */
final readonly class McpDelegationConfiguration
{
    public function __construct(private ExtensionConfiguration $configuration) {}

    /**
     * @return array<array-key,mixed>
     */
    public function definition(string $identifier): array
    {
        McpAuthValidation::profileIdentifier($identifier);
        $profiles = $this->configuration->get('nr_llm', 'mcpDelegationProfiles');
        $definition = is_array($profiles) ? $profiles[$identifier] ?? null : null;
        if (!is_array($definition)) {
            throw new InvalidArgumentException('Delegation profile is unavailable.', 8496712613);
        }

        return $definition;
    }

    public function profile(string $identifier): McpDelegationProfile
    {
        $definition = $this->definition($identifier);
        $clientSecret = $definition['clientSecretIdentifier'] ?? null;
        if ($clientSecret !== null && !is_string($clientSecret)) {
            throw new InvalidArgumentException('Invalid client credential configuration.', 8589299137);
        }

        return new McpDelegationProfile(
            $identifier,
            self::stringValue($definition, 'tokenEndpoint'),
            self::stringValue($definition, 'clientId'),
            $clientSecret,
            self::stringList($definition, 'allowedAudiences'),
            self::stringList($definition, 'allowedScopes'),
        );
    }

    /**
     * @param array<array-key,mixed> $values
     */
    public static function stringValue(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('Missing delegation string configuration.', 7476435240);
        }

        return $value;
    }

    /**
     * @param array<array-key,mixed> $values
     *
     * @return list<string>
     */
    public static function stringList(array $values, string $key): array
    {
        $value = $values[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('Missing delegation permission configuration.', 4162955553);
        }

        foreach ($value as $entry) {
            if (!is_string($entry)) {
                throw new InvalidArgumentException('Delegation permissions must be strings.', 7397625239);
            }
        }

        return $value;
    }
}
