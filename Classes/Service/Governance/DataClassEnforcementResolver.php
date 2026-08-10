<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Governance;

use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Resolves ``tools.dataClassEnforcement`` — whether the trust-zone axis of the
 * tool gate is enforced or merely observed (ADR-113).
 *
 * Extracted verbatim from {@see ToolCallPolicy} so the runtime gate and the
 * read-only governance readout (ADR-140) answer the same question through the
 * same code. A second reader that re-implemented the parsing could drift from
 * the gate and show an operator a mode the gate does not apply.
 *
 * Fail-closed: the axis observes ONLY on an explicit `observe`. Every other
 * case enforces — an unreadable extension configuration, a malformed `tools`
 * section, a missing value, or a typo (`observ`, `off`, ``). The gate is a
 * security control, so an operator who cannot express a deliberate "only
 * observe" gets the safe behaviour rather than a silently disabled gate.
 * Turning the axis on never loosens anything (the four pre-existing gates
 * always enforce), so failing closed here cannot over-permit.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class DataClassEnforcementResolver
{
    public const MODE_ENFORCE = 'enforce';

    public const MODE_OBSERVE = 'observe';

    public function __construct(
        private ?ExtensionConfiguration $extensionConfiguration = null,
    ) {}

    /**
     * Whether the trust-zone axis is enforced (true) or merely observed (false).
     */
    public function enforcing(): bool
    {
        try {
            /** @var array<string, mixed> $config */
            $config = $this->extensionConfiguration?->get('nr_llm') ?? [];
        } catch (Throwable) {
            return true;
        }

        $tools = $config['tools'] ?? null;
        if (!is_array($tools)) {
            return true;
        }

        $mode = $tools['dataClassEnforcement'] ?? null;

        return !is_string($mode) || strtolower(trim($mode)) !== self::MODE_OBSERVE;
    }

    /**
     * The effective mode as a label for the governance readout. Derived from
     * {@see enforcing()} rather than from the raw setting, so what is shown is
     * exactly what the gate applies — a typo reads as `enforce`, because that is
     * what happens.
     *
     * @return self::MODE_*
     */
    public function mode(): string
    {
        return $this->enforcing() ? self::MODE_ENFORCE : self::MODE_OBSERVE;
    }
}
