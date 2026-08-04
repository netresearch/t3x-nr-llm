<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A source of tools that are not known when the container is compiled.
 *
 * Builtin tools are DI-tagged classes, so the registry knows them at compile
 * time. A tool that comes from operator configuration — an MCP server's
 * imported catalogue (ADR-116) — cannot be: it exists because a row exists.
 *
 * The set of PROVIDERS stays compile-fixed and declarative; only the set of
 * TOOLS becomes dynamic. A provider is asked once per registry instance, on
 * first use rather than at construction, because {@see ToolRegistry} is built
 * on request paths that have nothing to do with an agent run.
 *
 * A provider must not perform network I/O. It reads what an explicit
 * administrator action already persisted; discovery is never on a request path.
 */
#[AutoconfigureTag(name: ToolProviderInterface::TAG_NAME)]
interface ToolProviderInterface
{
    public const TAG_NAME = 'nr_llm.tool_provider';

    /**
     * The tools this provider currently supplies.
     *
     * @return iterable<ToolInterface>
     */
    public function tools(): iterable;
}
