<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Runtime;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendIntent;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aMessage;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTask;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTaskState;
use Netresearch\NrLlm\Utility\SecretShapeRedactorTrait;

/**
 * What the model reads as the result of a delegation (spec 051 A2A-R9,
 * A2A-R10, A2A-R11).
 *
 * Every text names its origin first: what follows was written by a remote
 * agent and is tool output, never an instruction of this installation
 * (A2A-SEC8). The loop bounds it like any tool output, and every text passes
 * the shared secret redactor first: a remote agent may echo what the delegated
 * message carried, and the same text is stored as the step.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aToolResults
{
    use SecretShapeRedactorTrait;

    public static function message(string $agent, A2aMessage $message): ToolResult
    {
        return self::text(self::origin($agent) . ' answered directly:' . "\n" . $message->text());
    }

    /**
     * A task in a terminal or interrupted state. COMPLETED is a result, an
     * interrupted state is returned as such (stage 2 does not answer it), and
     * every other ending is an error the model can explain.
     */
    public static function task(string $agent, A2aTask $task): ToolResult
    {
        $state   = $task->status->state;
        $head    = sprintf('%s task %s ended in %s.', self::origin($agent), $task->id, $task->status->rawState);
        $content = trim($task->artifactText());
        $status  = trim($task->status->message?->text() ?? '');

        if ($state === A2aTaskState::COMPLETED) {
            return self::text($head . ($content !== '' ? "\n" . $content : ' It returned no artifact.'));
        }

        if ($state instanceof A2aTaskState && $state->isInterrupted()) {
            return self::text(sprintf(
                '%s task %s stopped in %s and waits for something this installation cannot give it yet; nothing was answered.%s',
                self::origin($agent),
                $task->id,
                $task->status->rawState,
                $status !== '' ? "\nThe remote agent said:\n" . $status : '',
            ));
        }

        return self::error($head . ($status !== '' ? "\nThe remote agent said:\n" . $status : ''));
    }

    public static function timedOut(string $agent, string $remoteTaskId, int $waitedSeconds): ToolResult
    {
        return ToolResult::error(sprintf(
            'Error: %s task %s did not finish within %d seconds. Its cancellation was requested; there is no result.',
            self::origin($agent),
            $remoteTaskId,
            $waitedSeconds,
        ));
    }

    public static function refused(string $agent, A2aSendIntent $intent): ToolResult
    {
        return self::error(sprintf(
            'Error: %s refused the request (%s%s). Nothing was done there.',
            self::origin($agent),
            $intent->errorReason !== '' ? $intent->errorReason : 'no reason given',
            $intent->errorCode !== null ? ', code ' . $intent->errorCode : '',
        ));
    }

    /**
     * A2A-C6: the request may have reached the remote. It is not resent; the
     * message id is what an operator looks the task up by.
     */
    public static function outcomeUnknown(string $agent, A2aSendIntent $intent): ToolResult
    {
        return self::error(sprintf(
            'Error: outcome unknown. The request to %s may have arrived, but no answer was read (%s). It is not sent again; an operator can look it up by the message id %s.',
            self::origin($agent),
            $intent->errorReason,
            $intent->messageId,
        ));
    }

    public static function unavailable(string $reason): ToolResult
    {
        return self::error('Error: the remote agent cannot be used: ' . $reason . '. Nothing was sent.');
    }

    private static function text(string $content): ToolResult
    {
        return ToolResult::text(self::maskSecretShapes($content));
    }

    private static function error(string $content): ToolResult
    {
        return ToolResult::error(self::maskSecretShapes($content));
    }

    private static function origin(string $agent): string
    {
        return sprintf('[via the remote A2A agent "%s"] The remote agent', $agent);
    }
}
