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
 * A catalogue-backed MCP tool with the operator's server data classification.
 * Remote tools always declare NON_IDEMPOTENT_WRITE: their implementation is
 * uninspectable, and remote read-only annotations cannot permit replay.
 * Approval follows the operator-declared server flag independently of that
 * effect. The administrator requirement is unconditional; a remote server
 * cannot grant permission through its catalogue or annotations.
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
                $context->actor,
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
            //
            // A cancelled call takes the same route and is fail-closed the same
            // way, but says so (ADR-191): the run inspector would otherwise
            // show an operator's own cancel as a failure of a server that may
            // have been perfectly healthy.
            return $e->isCancellation()
                ? ToolResult::cancelled($e->getMessage())
                : ToolResult::error($e->getMessage());
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
