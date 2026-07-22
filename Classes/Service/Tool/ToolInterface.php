<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A PHP "tool" the model may call mid-generation during the agent loop.
 *
 * Implementations are admin-curated, read-only, and return a typed
 * {@see ToolResult}. They are discovered via the `nr_llm.tool` DI tag
 * (auto-applied by AutoconfigureTag, mirroring ProviderMiddlewareInterface) and
 * collected by ToolRegistry through a tagged iterator.
 *
 * Security contract: a tool's array `$arguments` are model-chosen and
 * therefore attacker-influenceable (the model is steerable by injected,
 * externally-authored skill prose). The result's `content` egresses to BOTH
 * the external provider AND the rendered backend DOM. Any `artifacts` egress
 * to the backend DOM and the persisted audit stream ONLY — the runtime never
 * places them on the provider wire. Implementations MUST treat `$arguments`
 * as untrusted (validate and scope them, never expose secrets) and MUST apply
 * the SAME redaction to an artifact's structured `data` that they apply to
 * `content` — an artifact must never re-expose a field the text path
 * deliberately withheld.
 *
 * Authorization is enforced at TWO levels (see {@see requiresAdmin()}):
 * a coarse admin-only tier checked by the runtime ({@see ToolLoopService}
 * resolves the acting `$GLOBALS['BE_USER']` and never offers an admin-only
 * tool to a non-admin), and — for tools that touch user-scoped data — a
 * fine-grained self-enforcement of the ACTING user's TYPO3 permissions
 * inside `execute()` (page perms / `tables_select` / accessible file
 * storages). Both fail CLOSED when no `BackendUserAuthentication` is present.
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
     * Execute the tool with the model-provided arguments and return a typed
     * {@see ToolResult}: a provider-facing `content` string, an error flag, and
     * an optional list of run-only {@see \Netresearch\NrLlm\Domain\ValueObject\ToolArtifact}s
     * for richer backend rendering. The content is fed back into the
     * conversation as a tool turn; the artifacts never reach the provider (see
     * the security contract above).
     *
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments): ToolResult;

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

    /**
     * Whether this tool may only ever be offered to a backend ADMIN.
     *
     * `true` for tools exposing system / host / cross-user data that a
     * non-admin must never reach (logs, environment, phpinfo, the backend
     * user/group listings). {@see ToolLoopService} resolves the acting
     * `$GLOBALS['BE_USER']` and filters every admin-only tool out of the
     * offered set when that user is not an admin — fail-closed when no
     * backend user is present.
     *
     * `false` for tools usable by a non-admin; those that read user-scoped
     * records additionally self-enforce the acting user's own TYPO3
     * permissions inside {@see execute()} (page perms / `tables_select` /
     * accessible file storages), so a non-admin only ever sees what they are
     * already entitled to in the backend. An admin bypasses that narrowing
     * (TYPO3 admins see everything).
     */
    public function requiresAdmin(): bool;

    /**
     * The tool's group — a short, stable identifier used to enable or disable
     * whole families of tools at once (Tools module group toggles, the
     * per-configuration `allowed_tool_groups` gate and the playground's
     * grouped checkboxes).
     *
     * Builtins use the curated taxonomy `content`, `structure`,
     * `configuration`, `code`, `files`, `system`, `accounts`, `rag`.
     * Third-party tools declare their own group;
     * the recommended value is the providing extension's key. Enablement
     * cascades fail-closed: a tool is only offered when its group is enabled
     * AND the tool itself is enabled AND the run's configuration permits the
     * group — a per-tool override never re-enables a tool inside a disabled
     * group.
     */
    public function getGroup(): string;
}
