<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * How sensitive the data a tool returns is, as an egress ladder (ADR-094).
 *
 * Tool safety used to rest on denylists of table and field names, and every
 * audit found another name nobody had thought of. A classification inverts the
 * question: instead of asking "is this particular name suspicious", a tool
 * declares what *kind* of data it returns, and the provider the answer travels
 * to declares how much it may receive. A new credential-shaped field then
 * cannot leak by being unlisted, because the tool that would expose it is
 * already classified above the ceiling.
 *
 * The cases are totally ordered by {@see rank()}, from world-readable content
 * up to data that sits next to secrets.
 *
 * Two imprecisions are deliberate and worth stating plainly:
 *
 * - **Jurisdiction is not secrecy.** A single ladder collapses "how secret" and
 *   "where may it go" onto one axis. An operator who needs those apart can move
 *   a provider to a different zone, but the model cannot express, say,
 *   "editorial content, EU only".
 * - **Personal data has no case of its own.** The `accounts` tools return
 *   backend users — a GDPR concern, not a secrecy level. Mapping them to
 *   {@see self::SECRET_ADJACENT} is conservative rather than semantically
 *   precise; a proper PERSONAL_DATA concept needs its own axis, not a rank.
 */
enum ToolDataClass: string
{
    /**
     * Already world-readable: published pages, public search results.
     */
    case PUBLIC_CONTENT = 'publicContent';

    /**
     * Unpublished records, drafts, FAL metadata — editorial, not public.
     */
    case EDITOR_CONTENT = 'editorContent';

    /**
     * Source of the installation: extension code, templates, TypoScript files.
     */
    case SOURCE_CODE = 'sourceCode';

    /**
     * TCA, TypoScript, TSconfig, site configuration — how the site is wired.
     */
    case INTERNAL_CONFIGURATION = 'internalConfiguration';

    /**
     * Environment, phpinfo, logs, extension inventory — operational internals.
     */
    case SYSTEM_DIAGNOSTICS = 'systemDiagnostics';

    /**
     * Sits next to credentials: vault references, account data, exception bodies.
     */
    case SECRET_ADJACENT = 'secretAdjacent';

    /**
     * Position on the ladder, 0 = least sensitive. Mirrors the rank/severity
     * idiom of {@see SkillTrustLevel} and {@see PrivacyLevel}.
     */
    public function rank(): int
    {
        return match ($this) {
            self::PUBLIC_CONTENT => 0,
            self::EDITOR_CONTENT => 1,
            self::SOURCE_CODE => 2,
            self::INTERNAL_CONFIGURATION => 3,
            self::SYSTEM_DIAGNOSTICS => 4,
            self::SECRET_ADJACENT => 5,
        };
    }

    /**
     * Whether this class is within the given ceiling — the comparison the
     * egress gate makes.
     */
    public function isAtMost(self $ceiling): bool
    {
        return $this->rank() <= $ceiling->rank();
    }

    /**
     * The more sensitive of two classes, so a composite (a group's default over
     * its worst member, for instance) always errs upward.
     */
    public static function strictest(self $a, self $b): self
    {
        return $a->rank() >= $b->rank() ? $a : $b;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }

    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
