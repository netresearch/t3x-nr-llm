<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Transport;

use Netresearch\NrLlm\Service\Tool\EgressPolicyService;
use Netresearch\NrLlm\Service\Tool\Web\AddressClassifierInterface;
use Netresearch\NrLlm\Service\Tool\Web\ExternalFetchSettings;
use Netresearch\NrLlm\Service\Tool\Web\ExternalUrlGuard;
use Netresearch\NrLlm\Service\Tool\Web\HostResolverInterface;
use Netresearch\NrLlm\Service\Tool\Web\ProxyDetector;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Builds the address guard of the A2A client (ADR-279).
 *
 * The guard is ADR-202's {@see ExternalUrlGuard}, with the host lists of
 * `a2a.client` instead of the web tool's. Its classifier is the real one,
 * except in the Testing context when test networks are named (A2A-SEC10,
 * {@see A2aTestNetworkAddressClassifier}). The context is read when the
 * guard is built, not when the container is compiled, because the container
 * cache does not depend on the application context.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aUrlGuardFactory
{
    /** Egress group of every call to a registered remote agent, card import included. */
    public const GROUP_AGENT = 'a2a_agent';

    public function __construct(
        private EgressPolicyService $egressPolicy,
        private HostResolverInterface $resolver,
        private AddressClassifierInterface $classifier,
        private ExtensionConfiguration $extensionConfiguration,
        private ProxyDetector $proxyDetector,
        private ?ApplicationContext $context = null,
    ) {}

    public function create(): ExternalUrlGuard
    {
        return new ExternalUrlGuard(
            $this->egressPolicy,
            $this->resolver,
            $this->classifier(),
            new ExternalFetchSettings($this->extensionConfiguration, ['a2a', 'client']),
            $this->proxyDetector,
        );
    }

    /**
     * The classifier the guard uses; public so a test can tell which.
     */
    public function classifier(): AddressClassifierInterface
    {
        $context = $this->context ?? Environment::getContext();
        if (!$context->isTesting()) {
            return $this->classifier;
        }

        $networks = $this->configuredTestNetworks();
        if ($networks === []) {
            return $this->classifier;
        }

        return new A2aTestNetworkAddressClassifier($this->classifier, $networks);
    }

    /**
     * @return list<string>
     */
    private function configuredTestNetworks(): array
    {
        $node = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        foreach (['EXTCONF', 'nr_llm', 'a2aTestPublicNetworks'] as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return [];
            }

            $node = $node[$segment];
        }

        if (!is_array($node)) {
            return [];
        }

        return array_values(array_filter($node, static fn (mixed $network): bool => is_string($network) && $network !== ''));
    }
}
