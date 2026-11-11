<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * What the installation says about its A2A server role (spec 051 A2A-SV1,
 * A2A-SV8, A2A-SV9, A2A-SEC6, A2A-OPS2): the switch and limits of the
 * extension configuration, and each site's base URL.
 *
 * The base URL is a site setting (`nrllm.a2a.baseUrl`) without a default; a
 * site without it answers no A2A request. An unreadable value is a switch
 * that is off and the smallest limit, never a wider one.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aServerSettings
{
    public const SITE_BASE_URL = 'nrllm.a2a.baseUrl';

    public const BLOCKING_WAIT_DEFAULT = 20;

    public const BLOCKING_WAIT_MAX = 100;

    public const WAITING_REQUESTS_DEFAULT = 4;

    public const REQUEST_BYTES_DEFAULT = 1048576;

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
        private SiteFinder $siteFinder,
    ) {}

    public function enabled(): bool
    {
        return in_array($this->value('enabled'), [true, 1, '1'], true);
    }

    /**
     * Seconds a blocking SendMessage waits, 1 to 100 (ADR-283).
     */
    public function blockingWaitSeconds(): int
    {
        return $this->bounded('blockingWaitSeconds', self::BLOCKING_WAIT_DEFAULT, 1, self::BLOCKING_WAIT_MAX);
    }

    public function maxWaitingRequests(): int
    {
        return $this->bounded('maxWaitingRequests', self::WAITING_REQUESTS_DEFAULT, 0, 1000);
    }

    public function maxRequestBytes(): int
    {
        return $this->bounded('maxRequestBytes', self::REQUEST_BYTES_DEFAULT, 1024, 67108864);
    }

    /**
     * The configured base URL of every site that names one, by site
     * identifier, normalised: no trailing slash, no query, no fragment.
     *
     * @return array<string, A2aBaseUrl>
     */
    public function baseUrls(): array
    {
        $bases = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $base = $this->baseUrlOf($site);
            if ($base instanceof A2aBaseUrl) {
                $bases[$site->getIdentifier()] = $base;
            }
        }

        return $bases;
    }

    public function baseUrlOf(Site $site): ?A2aBaseUrl
    {
        try {
            $value = $site->getSettings()->get(self::SITE_BASE_URL);
        } catch (Throwable) {
            $value = null;
        }

        if (!is_string($value)) {
            return null;
        }

        return A2aBaseUrl::fromString($value);
    }

    private function bounded(string $key, int $default, int $min, int $max): int
    {
        $value = $this->value($key);
        $int   = is_numeric($value) ? (int) $value : $default;

        return max($min, min($max, $int));
    }

    private function value(string $key): mixed
    {
        try {
            $configuration = $this->extensionConfiguration->get('nr_llm');
        } catch (Throwable) {
            return null;
        }

        $node = is_array($configuration) ? ($configuration['a2a'] ?? null) : null;
        $node = is_array($node) ? ($node['server'] ?? null) : null;

        return is_array($node) ? ($node[$key] ?? null) : null;
    }
}
