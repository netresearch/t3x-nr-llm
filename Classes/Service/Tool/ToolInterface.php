<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A PHP "tool" the model may call mid-generation during the agent loop.
 *
 * Implementations are admin-curated, read-only, and return a plain string.
 * They are discovered via the `nr_llm.tool` DI tag (auto-applied by
 * AutoconfigureTag, mirroring ProviderMiddlewareInterface) and collected by
 * ToolRegistry through a tagged iterator.
 *
 * Security contract: a tool runs with full TYPO3 privileges and has NO
 * per-record authorization. Its array `$arguments` are model-chosen and
 * therefore attacker-influenceable (the model is steerable by injected,
 * externally-authored skill prose), and its return value egresses both to
 * the external provider AND the rendered backend DOM. Implementations MUST
 * assume an authorized-admin caller and treat `$arguments` as untrusted:
 * validate and scope them, and never expose secrets.
 */
#[AutoconfigureTag(name: self::TAG_NAME)]
interface ToolInterface
{
    public const TAG_NAME = 'nr_llm.tool';

    /**
     * The tool declaration (name, description, JSON-Schema parameters) the
     * model receives and points back at by name via a ToolCall.
     */
    public function getSpec(): ToolSpec;

    /**
     * Execute the tool with the model-provided arguments and return a string
     * result that is fed back into the conversation as a tool turn.
     *
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments): string;

    /**
     * Whether this tool is offered by default when an admin has not set an
     * explicit global override (see {@see ToolStateRepository}).
     *
     * Curated, low-risk tools return `true`. "Raw" tools whose output can
     * egress server/host secrets to the external provider return `false`, so
     * they are unavailable until an admin deliberately enables them in the
     * Tool Playground module — defence in depth on top of the admin-only
     * context.
     */
    public function isEnabledByDefault(): bool;
}
