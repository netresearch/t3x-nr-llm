<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * The curated tool groups this extension ships, and the label each one renders
 * under (ADR-152).
 *
 * This enum is NOT the type of {@see \Netresearch\NrLlm\Service\Tool\ToolInterface::getGroup()}
 * and deliberately does not become it. A group is an OPEN set: a third-party
 * tool declares its own group — the recommended value is the providing
 * extension's key — and both the `allowed_tool_groups` item provider and the
 * egress policy already treat an unknown group as ordinary. Narrowing
 * `getGroup()` to this enum would close a set that must stay open, and it would
 * break an `@api` interface to do it.
 *
 * What IS closed is the taxonomy this repository ships and must therefore be
 * able to name in a UI. That set was previously written out twice — in
 * {@see \Netresearch\NrLlm\Service\Tool\ToolDataClassResolver} and in the
 * builtin-group test — with nothing tying the two together. An enum makes it
 * exhaustive by construction: a new curated group cannot be added without a
 * case, and a case cannot exist without a label. A value object wrapping a
 * string would accept any value, which is precisely the openness the bare
 * string already provides, so it would add a type without adding a guarantee.
 *
 * A group outside this list resolves to `null` through {@see self::tryFrom()}
 * and the module falls back to rendering the raw identifier — a third-party
 * group stays visible and toggleable, it simply has no translated name.
 */
enum ToolGroup: string
{
    /**
     * Reads editorial records: pages, content elements, record history.
     */
    case CONTENT = 'content';

    /**
     * WRITES editorial records. Every member is a narrow editor action (ADR-152).
     */
    case EDITING = 'editing';

    /**
     * Reads the shape of things: page tree, TCA, table and FlexForm schemas.
     */
    case STRUCTURE = 'structure';

    /**
     * Reads TypoScript, TSconfig, site configuration and Fluid resolution.
     */
    case CONFIGURATION = 'configuration';

    /**
     * Reads source files, code search and the last exception.
     */
    case CODE = 'code';

    /**
     * Reads FAL storages, folders, files and their references.
     */
    case FILES = 'files';

    /**
     * Reads host and installation diagnostics: environment, logs, extensions, status.
     */
    case SYSTEM = 'system';

    /**
     * Reads backend users and groups.
     */
    case ACCOUNTS = 'accounts';

    /**
     * Answers site-search queries over publicly visible documents.
     */
    case RAG = 'rag';

    private const LABEL_PREFIX = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:tool.group.';

    /**
     * The `LLL:` key of this group's human name.
     *
     * Derived from the case value rather than written out per case: the two
     * would drift, and the derivation is pinned by a test that resolves every
     * case's key in both the English and the German catalogue.
     */
    public function labelKey(): string
    {
        return self::LABEL_PREFIX . $this->value;
    }

    /**
     * The label key of a group NAME, or null when the group is not one of the
     * curated ones — a third-party tool's group, or one an operator's stored
     * configuration still names after its extension was removed.
     */
    public static function labelKeyFor(string $group): ?string
    {
        return self::tryFrom($group)?->labelKey();
    }
}
