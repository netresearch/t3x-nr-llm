<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Protocol;

/**
 * One `StreamResponse` (proto): exactly one of a task, a message, a status
 * update or an artifact update (spec 051 A2A-SSE6, A2A-D2).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aStreamEvent
{
    public const KIND_TASK = 'task';

    public const KIND_MESSAGE = 'message';

    public const KIND_STATUS_UPDATE = 'statusUpdate';

    public const KIND_ARTIFACT_UPDATE = 'artifactUpdate';

    /**
     * @param array<string, mixed> $metadata
     */
    private function __construct(
        public string $kind,
        public string $taskId,
        public string $contextId,
        public ?A2aTask $task = null,
        public ?A2aMessage $message = null,
        public ?A2aTaskStatus $status = null,
        public ?A2aArtifact $artifact = null,
        public bool $append = false,
        public bool $lastChunk = false,
        public array $metadata = [],
    ) {}

    public static function ofTask(A2aTask $task): self
    {
        return new self(self::KIND_TASK, $task->id, $task->contextId, task: $task, status: $task->status);
    }

    public static function ofMessage(A2aMessage $message): self
    {
        return new self(self::KIND_MESSAGE, $message->taskId, $message->contextId, message: $message);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function ofStatusUpdate(string $taskId, string $contextId, A2aTaskStatus $status, array $metadata): self
    {
        return new self(self::KIND_STATUS_UPDATE, $taskId, $contextId, status: $status, metadata: $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function ofArtifactUpdate(string $taskId, string $contextId, A2aArtifact $artifact, bool $append, bool $lastChunk, array $metadata): self
    {
        return new self(self::KIND_ARTIFACT_UPDATE, $taskId, $contextId, artifact: $artifact, append: $append, lastChunk: $lastChunk, metadata: $metadata);
    }

    /**
     * Whether the event names a task: every kind but a message without one.
     */
    public function namesTask(): bool
    {
        return $this->taskId !== '';
    }
}
