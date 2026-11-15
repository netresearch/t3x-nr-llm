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

    public const STREAM_SECONDS_DEFAULT = 300;

    public const STREAMS_PER_TASK_DEFAULT = 2;

    public const STREAMS_PER_CLIENT_DEFAULT = 4;

    public const STREAMS_DEFAULT = 8;

    public const FRAME_BYTES_DEFAULT = 1048576;

    public const PUSH_CONFIGS_PER_TASK_DEFAULT = 4;

    public const PUSH_CONFIGS_PER_CLIENT_DEFAULT = 100;

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
     * Whether the card declares `streaming` and the two streaming methods
     * answer (spec 051 A2A-OPS2). On by default, as ext_conf_template.txt
     * says: a key TYPO3 has not written yet is on, so the behaviour does not
     * depend on whether the template's defaults were synchronised. Any value
     * other than on switches it off.
     */
    public function streamingEnabled(): bool
    {
        $value = $this->value('streaming');

        return $this->enabled() && ($value === null || in_array($value, [true, 1, '1'], true));
    }

    /**
     * Whether the card declares `pushNotifications` and the push methods
     * answer (spec 051 A2A-OPS2, ADR-285). Off unless switched on: a push is
     * an outbound call to an address the client chooses.
     */
    public function pushEnabled(): bool
    {
        return $this->enabled() && in_array($this->value('push'), [true, 1, '1'], true);
    }

    public function maxPushConfigsPerTask(): int
    {
        return $this->bounded('maxPushConfigsPerTask', self::PUSH_CONFIGS_PER_TASK_DEFAULT, 1, 100);
    }

    public function maxPushConfigsPerClient(): int
    {
        return $this->bounded('maxPushConfigsPerClient', self::PUSH_CONFIGS_PER_CLIENT_DEFAULT, 1, 100000);
    }

    /**
     * Seconds one stream stays open at most, 1 to 3600 (A2A-SSE3).
     */
    public function streamMaxSeconds(): int
    {
        return $this->bounded('streamMaxSeconds', self::STREAM_SECONDS_DEFAULT, 1, 3600);
    }

    public function maxStreamsPerTask(): int
    {
        return $this->bounded('maxStreamsPerTask', self::STREAMS_PER_TASK_DEFAULT, 0, 100);
    }

    public function maxStreamsPerClient(): int
    {
        return $this->bounded('maxStreamsPerClient', self::STREAMS_PER_CLIENT_DEFAULT, 0, 1000);
    }

    public function maxStreams(): int
    {
        return $this->bounded('maxStreams', self::STREAMS_DEFAULT, 0, 1000);
    }

    /**
     * The largest frame a stream writes, in bytes (A2A-SSE3).
     */
    public function maxFrameBytes(): int
    {
        return $this->bounded('maxFrameBytes', self::FRAME_BYTES_DEFAULT, 4096, 16777216);
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
