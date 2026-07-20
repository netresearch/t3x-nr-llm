<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * The network egress a tool group is permitted (ADR-061).
 *
 * Egress is governed per tool *group* (ADR-043) and is fail-closed: a group
 * with no declared policy resolves to {@see self::NONE} and may make no
 * outbound request. The only positive scope shipped is {@see self::OWN_SITE}
 * — the instance's own configured site hosts, matching the existing per-tool
 * URL allow-listing (e.g. `probe_url`), now lifted to the group boundary.
 *
 * Deliberately minimal: no free-form "any host" scope exists, so a new or
 * mis-declared group can never egress to an arbitrary target.
 */
enum ToolEgressScope: string
{
    /**
     * No outbound network request permitted (fail-closed default).
     */
    case NONE = 'none';

    /**
     * Only the instance's own configured site hosts (SiteFinder bases).
     */
    case OWN_SITE = 'own_site';

    /**
     * An operator-declared service endpoint that is not a site base — the
     * search backend a RAG tool queries, for example. The host comes from the
     * site configuration, never from the model, and the request must match that
     * declaration exactly. OWN_SITE cannot express it: a Solr host is not a
     * site base, so without this case the policy could only be satisfied by
     * misdeclaring the group (ADR-093).
     */
    case CONFIGURED_ENDPOINT = 'configured_endpoint';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $c): string => $c->value, self::cases());
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * Whether this scope permits any outbound request at all.
     */
    public function permitsEgress(): bool
    {
        return $this !== self::NONE;
    }
}
