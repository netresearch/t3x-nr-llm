<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunReference;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Domain\ValueObject\McpToolRecord;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Agent\AgentRunCancellationSignalFactory;
use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrLlm\Service\Tool\RemoteApprovalInterface;
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
 * - The APPROVAL requirement is the one the operator declared on the server
 *   (ADR-134). It cannot be read off the effect: `getEffect()` below is a
 *   fail-closed assumption about an uninspectable body, and treating that as
 *   consent-worthy would suspend every remote call including a pure search.
 * - `requiresAdmin()` is hard-wired true. It is not derived from anything the
 *   server sends, because the server is the party the guard exists against.
 */
final readonly class McpTool implements ToolInterface, RemoteToolInterface, RemoteApprovalInterface, ToolDataClassInterface, ToolEffectInterface
{
    /**
     * @param array<string, mixed> $inputSchema      the normalised parameter schema
     * @param bool                 $requiresApproval the server's operator-declared approval flag,
     *                                               already read fail-closed by
     *                                               {@see McpServerRecord::approvalRequired()}
     */
    public function __construct(
        private McpServerRecord $server,
        private McpToolRecord $record,
        private array $inputSchema,
        private ToolDataClass $dataClass,
        private bool $requiresApproval,
        private McpClient $client,
        private ?AgentRunCancellationSignalFactory $cancellations = null,
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
            // Null outside a persisted run -- the Tool Playground and any bare
            // ToolLoopServiceInterface consumer -- and the call is then the
            // blocking one it always was. There is no run row to ask about, so
            // there is nothing a signal could observe (#774).
            $cancellation = $this->cancellations instanceof AgentRunCancellationSignalFactory
                && $context->run instanceof AgentRunReference
                    ? $this->cancellations->forRun($context->run->uuid)
                    : null;

            $outcome = $this->client->callTool(
                $this->server,
                $this->record->remoteName,
                $arguments,
                $cancellation,
            );

            // The two ways a remote call fails end the same way (ADR-161). The
            // server being unreachable is the loud one; `isError` on an
            // otherwise successful response is the ordinary one, and it is the
            // one a working server uses. Persisting it as a successful step
            // whose content reads like an error is the same defect either way.
            return $outcome->isError
                ? ToolResult::error($outcome->text)
                : ToolResult::text($outcome->text);
        } catch (McpTransportException $e) {
            // Returned rather than thrown: a server that is down is a fact
            // about this call, not a fault in the run. The loop can carry on
            // and the model is told plainly what failed. The message is already
            // bounded and control-stripped by the exception itself.
            return ToolResult::error($e->getMessage());
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
     * The value the operator set on the server, carried in rather than looked
     * up: this object is already built per catalogue row from that very server
     * record, so the flag rides along at no cost, while the approval scan that
     * asks for it runs once per tool call and must not grow a repository.
     */
    public function requiresApproval(): bool
    {
        return $this->requiresApproval;
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
