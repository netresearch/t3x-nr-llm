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
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class PlaygroundRunResponse
{
    /**
     * @param list<RunStep>                                                                                                             $steps
     * @param array{sources: list<array{source: string, dataClass: string|null}>, effective: string|null, effectiveSource: string}|null $contextClassification How sensitive what this run injected is (ADR-144/ADR-151); null when it was not computed — a resumed continuation, which reports the shape it always did.
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
        public ?array $contextClassification = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
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

        // Omitted rather than sent as null: absent means "not computed on this
        // path", which the client renders differently from "nothing declared".
        if ($this->contextClassification !== null) {
            $out['contextClassification'] = $this->contextClassification;
        }

        return $out;
    }
}
