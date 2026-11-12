<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Closure;
use Netresearch\NrLlm\Domain\Enum\ToolOutcome;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Exception\GuardrailApprovalRequiredException;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Observability\GenAiSemconv;
use Netresearch\NrLlm\Observability\GenAiTelemetry;
use Netresearch\NrLlm\Observability\ScopedSpan;
use Netresearch\NrLlm\Service\Agent\Exception\RunCancellationRequestedException;
use Netresearch\NrLlm\Service\Agent\Exception\RunLeaseLostException;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\Exception\ToolDependencyPendingException;
use Netresearch\NrLlm\Service\Tool\Exception\ToolDependencyRequiredException;
use Netresearch\NrLlm\Service\Tool\Exception\ToolInputRequiredException;
use OpenTelemetry\API\Trace\SpanKind;
use Throwable;

/**
 * The OpenTelemetry spans of the tool loop (ADR-291): `execute_tool` around one
 * tool execution and `invoke_agent` around one segment of a run.
 *
 * It lives beside the loop because it knows the loop's control flow, which the
 * core instrumentation ({@see GenAiTelemetry}) must not (ADR-090). Where the
 * host registered no SDK, or the setting is off, every method calls its closure
 * and returns. Nothing the tools or the model said is recorded: not the
 * arguments, not the result, not the prompt, not an exception message.
 */
final readonly class ToolTelemetry
{
    public function __construct(
        private GenAiTelemetry $telemetry,
    ) {}

    /**
     * An INTERNAL `execute_tool` span around one tool execution.
     *
     * A tool that returns a failed result is an error of the span; the tool's
     * own text is not recorded. A dependency tool that announces a pending
     * operation is control flow and ends the span without an error.
     *
     * @param Closure(): ToolResult $execute
     */
    public function traceTool(ToolCall $call, bool $remote, Closure $execute): ToolResult
    {
        $span = $this->telemetry->begin(
            GenAiSemconv::OPERATION_EXECUTE_TOOL . ' ' . $call->name,
            SpanKind::KIND_INTERNAL,
            [
                GenAiSemconv::ATTR_OPERATION_NAME => GenAiSemconv::OPERATION_EXECUTE_TOOL,
                GenAiSemconv::ATTR_TOOL_NAME      => $call->name,
                GenAiSemconv::ATTR_TOOL_TYPE      => $remote ? GenAiSemconv::TOOL_TYPE_EXTENSION : GenAiSemconv::TOOL_TYPE_FUNCTION,
                GenAiSemconv::ATTR_TOOL_CALL_ID   => $call->id,
            ],
        );
        if (!$span instanceof ScopedSpan) {
            return $execute();
        }

        $error = null;

        try {
            $result = $execute();
            if ($result->isError || $result->outcome === ToolOutcome::FAILED) {
                $error = 'tool_failed';
            }

            return $result;
        } catch (ToolDependencyPendingException $pending) {
            throw $pending;
        } catch (Throwable $e) {
            $error = $e::class;

            throw $e;
        } finally {
            $span->finish([], $error);
        }
    }

    /**
     * An INTERNAL `invoke_agent` span around one segment of a tool loop: a run
     * as it starts, or as it continues after an approval, an answer or a
     * finished dependency.
     *
     * The model calls and the tool executions of the segment are its
     * children. A pause for an approval, an answer or a dependency, a
     * cancellation, a lost lease and a guardrail verdict are outcomes of the
     * run, not failures; they set `nr_llm.run.outcome` and no error.
     *
     * @param Closure(): ToolLoopResult $run
     */
    public function traceAgent(string $agentName, ?string $runUuid, Closure $run): ToolLoopResult
    {
        $attributes = [GenAiSemconv::ATTR_OPERATION_NAME => GenAiSemconv::OPERATION_INVOKE_AGENT];
        if ($agentName !== '') {
            $attributes[GenAiSemconv::ATTR_AGENT_NAME] = $agentName;
        }

        if ($runUuid !== null && $runUuid !== '') {
            $attributes[GenAiSemconv::ATTR_CORRELATION_ID] = $runUuid;
        }

        $span = $this->telemetry->begin(
            GenAiSemconv::OPERATION_INVOKE_AGENT . ($agentName !== '' ? ' ' . $agentName : ''),
            SpanKind::KIND_INTERNAL,
            $attributes,
        );
        if (!$span instanceof ScopedSpan) {
            return $run();
        }

        $end   = [];
        $error = null;

        try {
            $result = $run();
            $end    = [
                GenAiSemconv::ATTR_RUN_ITERATIONS => $result->iterations,
                GenAiSemconv::ATTR_RUN_TRUNCATED  => $result->truncated,
                GenAiSemconv::ATTR_RUN_OUTCOME    => $result->terminationReason->value,
                ...GenAiSemconv::usageAttributes($result->usage, true),
            ];

            return $result;
        } catch (Throwable $e) {
            $outcome = $this->outcomeOf($e);
            if ($outcome === null) {
                $error = $e::class;
            } else {
                $end = [GenAiSemconv::ATTR_RUN_OUTCOME => $outcome];
            }

            throw $e;
        } finally {
            $span->finish($end, $error);
        }
    }

    /**
     * The outcome of a run that ended in an exception which is no failure, or
     * null for a failure.
     */
    private function outcomeOf(Throwable $e): ?string
    {
        return match (true) {
            $e instanceof ToolApprovalRequiredException,
            $e instanceof ToolInputRequiredException,
            $e instanceof ToolDependencyRequiredException   => 'suspended',
            $e instanceof RunCancellationRequestedException => 'cancelled',
            $e instanceof RunLeaseLostException             => 'lease_lost',
            $e instanceof GuardrailViolationException,
            $e instanceof GuardrailApprovalRequiredException => 'guardrail',
            default                                          => null,
        };
    }
}
