<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Client;

use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aBaseUrl;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aServerSettings;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * What the installation says about push notifications in its A2A client
 * role (spec 051 A2A-PUSH6, A2A-PUSH8, A2A-OPS2, ADR-285).
 *
 * - `a2a.client.push` switches the receiver on. Off (the default), no
 *   delegation registers a callback and the endpoint does not exist.
 * - `a2a.client.pushSite` names the site whose A2A base URL (`nrllm.a2a.baseUrl`)
 *   carries the endpoint `<base>/push`. The worker that registers a callback
 *   has no site of its own, so the site is named here. Without a site that
 *   has a base URL, nothing registers.
 * - The inbox bounds and the body limit; an unreadable value is the default,
 *   kept within its range.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aClientPushSettings
{
    public const PUSH_PATH = '/push';

    public const INBOX_PER_TASK_DEFAULT = 8;

    public const INBOX_DEFAULT = 1000;

    public const BODY_BYTES_DEFAULT = 65536;

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
        private SiteFinder $siteFinder,
        private A2aServerSettings $serverSettings,
    ) {}

    public function enabled(): bool
    {
        return in_array($this->value('push'), [true, 1, '1'], true);
    }

    /**
     * The base URL of the configured push site; null when push is off, no
     * site is named, the site does not exist or has no A2A base URL.
     */
    public function callbackBase(): ?A2aBaseUrl
    {
        $identifier = $this->value('pushSite');
        if (!$this->enabled() || !is_string($identifier) || trim($identifier) === '') {
            return null;
        }

        foreach ($this->siteFinder->getAllSites() as $site) {
            if ($site->getIdentifier() === trim($identifier)) {
                return $this->serverSettings->baseUrlOf($site);
            }
        }

        return null;
    }

    /**
     * The URL handed to a remote agent for its callbacks.
     */
    public function callbackUrl(): ?string
    {
        $base = $this->callbackBase();

        return $base instanceof A2aBaseUrl ? $base->origin() . $base->path . self::PUSH_PATH : null;
    }

    /**
     * Unreconciled callbacks one registration may hold, 1 to 100.
     */
    public function maxInboxPerTask(): int
    {
        return $this->bounded('maxPushInboxPerTask', self::INBOX_PER_TASK_DEFAULT, 1, 100);
    }

    /**
     * Unreconciled callbacks the installation holds at most, 1 to 100000.
     */
    public function maxInbox(): int
    {
        return $this->bounded('maxPushInbox', self::INBOX_DEFAULT, 1, 100000);
    }

    /**
     * The largest callback body, 1 KiB to 1 MiB.
     */
    public function maxBodyBytes(): int
    {
        return $this->bounded('maxPushBytes', self::BODY_BYTES_DEFAULT, 1024, 1048576);
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
        $node = is_array($node) ? ($node['client'] ?? null) : null;

        return is_array($node) ? ($node[$key] ?? null) : null;
    }
}
