<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Web;

use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The operator's settings for `fetch_external_url` (ADR-202), read from the
 * extension configuration under `tools.fetchExternalUrl`.
 *
 * Every accessor fails closed: configuration that cannot be read at all gives
 * null lists (the guard refuses every fetch) and false switches (approval
 * stays on, a proxy stays refused). An absent key is the documented default.
 */
final readonly class ExternalFetchSettings
{
    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * @return list<HostListEntry>|null null when the configuration cannot be read
     */
    public function allowedHosts(): ?array
    {
        return $this->hostList('allowedHosts');
    }

    /**
     * @return list<HostListEntry>|null null when the configuration cannot be read
     */
    public function deniedHosts(): ?array
    {
        return $this->hostList('deniedHosts');
    }

    /**
     * Whether a call may run without a human approval. Only when the operator
     * switched it on AND restricted the tool to a non-empty allowlist: the
     * approval exists because the URL itself carries data out, and the
     * allowlist is what bounds where it can go.
     */
    public function approvalCanBeSkipped(): bool
    {
        $allowed = $this->allowedHosts();

        return $this->flag('skipApprovalWithAllowlist') && $allowed !== null && $allowed !== [];
    }

    /**
     * Whether a fetch may go through an HTTP proxy — then the proxy resolves the
     * host and the address pin does not reach past it. Only with a non-empty
     * allowlist, for the same reason as {@see self::approvalCanBeSkipped()}.
     */
    public function proxyPermitted(): bool
    {
        $allowed = $this->allowedHosts();

        return $this->flag('allowViaProxy') && $allowed !== null && $allowed !== [];
    }

    private function flag(string $key): bool
    {
        $value = $this->value($key);

        return in_array($value, [true, 1, '1'], true);
    }

    /**
     * @return list<HostListEntry>|null
     */
    private function hostList(string $key): ?array
    {
        $value = $this->value($key);
        if ($value === false) {
            return null;
        }

        if (!is_string($value)) {
            return [];
        }

        $entries = [];
        foreach (explode(',', $value) as $raw) {
            if (trim($raw) === '') {
                continue;
            }

            $entry = HostListEntry::parse($raw);
            if (!$entry instanceof HostListEntry) {
                // An entry that cannot be read must not shrink an allowlist to
                // nothing, which the guard reads as "every public host", nor
                // drop a host the operator meant to deny: the list is unreadable.
                return null;
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * The raw value, null when absent, false when the configuration cannot be
     * read.
     */
    private function value(string $key): mixed
    {
        try {
            $node = $this->extensionConfiguration->get('nr_llm');
        } catch (Throwable) {
            return false;
        }

        foreach (['tools', 'fetchExternalUrl', $key] as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return $node;
    }
}
