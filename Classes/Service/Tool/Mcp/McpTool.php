<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Domain\ValueObject\McpToolRecord;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrLlm\Service\Tool\RemoteToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolDataClassInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;

/**
 * One tool imported from one MCP server (ADR-116).
 *
 * Constructed per catalogue row by {@see McpToolProvider}, never by the
 * container: its arguments are a database row. It is therefore excluded from
 * autowiring in `Configuration/Services.yaml` — without that entry the
 * `nr_llm.tool` autoconfigure tag on {@see ToolInterface} would have the
 * container try to build it, and compilation fails on the scalar arguments.
 *
 * It declares both classifications explicitly, which is what makes
 * {@see RemoteToolInterface}'s rules effective without touching the resolvers:
 * each resolver already prefers an explicit declaration over its default, so
 * the operator's choice simply wins.
 *
 * - The DATA CLASS is the one the operator declared on the server. There is no
 *   code here to derive it from, and the group-default path would have landed
 *   on the fail-closed `SECRET_ADJACENT` for every `mcp_*` group, which reads
 *   like a decision but is only an absence.
 * - The EFFECT is a non-idempotent write unless the catalogue says otherwise.
 *   The builtin default is the opposite because every builtin reads; a remote
 *   tool's body is not ours to inspect, so the assumption has to be that it
 *   changed something and must not be replayed on a retry (ADR-111/112).
 * - `requiresAdmin()` is hard-wired true. It is not derived from anything the
 *   server sends, because the server is the party the guard exists against.
 */
final readonly class McpTool implements ToolInterface, RemoteToolInterface, ToolDataClassInterface, ToolEffectInterface
{
    /**
     * @param array<string, mixed> $inputSchema the normalised parameter schema
     */
    public function __construct(
        private McpServerRecord $server,
        private McpToolRecord $record,
        private array $inputSchema,
        private ToolDataClass $dataClass,
        private McpClient $client,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            $this->record->toolName,
            $this->description(),
            $this->inputSchema,
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        try {
            return ToolResult::text($this->client->callTool($this->server, $this->record->remoteName, $arguments));
        } catch (McpTransportException $e) {
            // Returned rather than thrown: a server that is down is a fact
            // about this call, not a fault in the run. The loop can carry on
            // and the model is told plainly what failed. The message is already
            // bounded and control-stripped by the exception itself.
            return ToolResult::text($e->getMessage());
        }
    }

    public function isEnabledByDefault(): bool
    {
        // An imported tool is inert until an operator enables it. Import is an
        // act of configuration; it should not also be an act of granting.
        return false;
    }

    public function requiresAdmin(): bool
    {
        return true;
    }

    public function getGroup(): string
    {
        return 'mcp_' . $this->server->identifier;
    }

    public function getDataClass(): ToolDataClass
    {
        return $this->dataClass;
    }

    public function getEffect(): ToolEffect
    {
        return ToolEffect::NON_IDEMPOTENT_WRITE;
    }

    /**
     * The description the model sees, with the origin stated first.
     *
     * The remote text is written by a third party and is read by a model that
     * treats it as instruction. Naming the server ahead of it does not make the
     * text safe, but it does mean the model is never told the sentence came
     * from this installation.
     */
    private function description(): string
    {
        $remote = trim($this->record->description);

        $origin = sprintf('[via the external MCP server "%s"]', $this->server->name);

        return $remote === '' ? $origin : $origin . ' ' . $remote;
    }
}
