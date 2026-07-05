<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\Response;

use Netresearch\NrLlm\Domain\ValueObject\RunStep;

/**
 * JSON payload for an admin playground run: the final answer plus the full,
 * ordered inspector trace (one step per model round-trip and per executed
 * tool call, or the assembled message list for a dry run) and the summed
 * usage.
 *
 * Built in {@see \Netresearch\NrLlm\Controller\Backend\ToolPlaygroundController::runAction()}
 * from a {@see \Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult} and the
 * {@see \Netresearch\NrLlm\Service\Tool\RunTrace} steps.
 */
final readonly class PlaygroundRunResponse
{
    /**
     * @param list<RunStep> $steps
     */
    public function __construct(
        public string $finalContent,
        public int $iterations,
        public bool $truncated,
        public bool $dryRun,
        public array $steps,
        public int $promptTokens,
        public int $completionTokens,
        public int $totalTokens,
        public ?float $estimatedCost,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success'      => true,
            'finalContent' => $this->finalContent,
            'iterations'   => $this->iterations,
            'truncated'    => $this->truncated,
            'dryRun'       => $this->dryRun,
            'steps'        => array_map(static fn(RunStep $step): array => $step->toArray(), $this->steps),
            'usage'        => [
                'promptTokens'     => $this->promptTokens,
                'completionTokens' => $this->completionTokens,
                'totalTokens'      => $this->totalTokens,
                'estimatedCost'    => $this->estimatedCost,
            ],
        ];
    }
}
