<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * Where a send's context window went, component by component (ADR-151).
 *
 * {@see ContextFitResult} already said *whether* a transcript fit and how many
 * turns were dropped to make it. It never said *what* filled the window, so an
 * operator whose run kept losing history had a number and no lever. This is
 * that accounting, and it is the same arithmetic the fit made — not a second
 * estimate taken alongside it.
 *
 * **The lines close.** ``transcriptTokens + toolSchemaTokens +
 * systemPromptTokens + skillTokens`` equals {@see estimatedTokens}, and
 * ``contextLength - reservedOutput - safetyMargin`` equals {@see budget}. A
 * component is a share of the one figure the pruning decision used, never an
 * independently rounded guess, so a reader can subtract.
 *
 * Three honesty notes, because a breakdown that hides its seams is worse than
 * none:
 *
 * - **Snippets have no line of their own.** A configuration's tag-selected
 *   snippets (ADR-031) are composed INTO the effective system prompt by
 *   :php:`ConfigurationSnippetResolver` before the estimator ever sees them, so
 *   at the point this is computed they are no longer distinct text.
 *   {@see systemPromptTokens} is therefore the prompt *including* whatever
 *   snippets were composed into it, and it is labelled that way everywhere it
 *   is rendered. Splitting it would mean changing what callers hand to
 *   :php:`ContextWindowManager::fit()`, which is a different decision.
 * - **{@see toolSchemaTokens} is a marginal cost.** It is what the tool-schema
 *   block adds on top of the transcript, not what the block would cost alone —
 *   which is what makes the four lines sum exactly.
 * - **{@see systemPromptTokens} is zero when the transcript already leads with
 *   a system message**, because then the prompt is inside
 *   {@see transcriptTokens}. {@see systemPromptInTranscript} distinguishes that
 *   from "there is no system prompt"; a surface must not render a bare 0 for
 *   both.
 *
 * Every figure is an estimate scaled by the manager's calibration factor
 * ({@see ContextFitResult::$calibration}), not a tokenizer count.
 *
 * @api
 */
final readonly class ContextBudgetBreakdown
{
    /**
     * @param int  $contextLength            the window this send was sized against; the manager's fallback when the model declares none
     * @param int  $reservedOutput           held back for the model's answer (per-call max tokens, else the model's output limit, else a floor)
     * @param int  $safetyMargin             the fraction of the window held back on top of the reserve
     * @param int  $budget                   what the send may occupy: contextLength - reservedOutput - safetyMargin
     * @param int  $transcriptTokens         the message list as it will be sent, after any pruning
     * @param int  $toolSchemaTokens         what the tool JSON-schema block adds on top of the transcript
     * @param int  $systemPromptTokens       the system prompt the send carries outside the list, snippets included; 0 when the list already leads with one
     * @param bool $systemPromptInTranscript whether the prompt is inside $transcriptTokens rather than counted separately
     * @param int  $skillTokens              prose a later stage injects outside the list — the composed skill block
     * @param int  $estimatedTokens          the sum of the four component lines; the figure the pruning decision used
     * @param int  $remaining                budget - estimatedTokens; negative when the send overflows
     */
    public function __construct(
        public int $contextLength,
        public int $reservedOutput,
        public int $safetyMargin,
        public int $budget,
        public int $transcriptTokens,
        public int $toolSchemaTokens,
        public int $systemPromptTokens,
        public bool $systemPromptInTranscript,
        public int $skillTokens,
        public int $estimatedTokens,
        public int $remaining,
    ) {}

    /**
     * No accounting was produced.
     *
     * The manager returns this when it hands the send to the provider without
     * estimating anything — a reserve larger than the whole window, or a
     * pruned list that failed the pairing guard. Distinct from an all-zero
     * measurement: nothing was measured, so a surface should say so rather
     * than render a window of zero.
     */
    public static function none(): self
    {
        return new self(0, 0, 0, 0, 0, 0, 0, false, 0, 0, 0);
    }

    /**
     * Whether anything was measured at all — false for {@see self::none()}.
     *
     * Serialised as ``measured`` so the client reads this definition instead of
     * re-deriving it: a predicate spelled out at four render sites is four
     * places to disagree with the server about what "no accounting" means.
     */
    public function isMeasured(): bool
    {
        return $this->contextLength > 0;
    }

    /**
     * @return array<string, int|bool>
     */
    public function toArray(): array
    {
        return [
            'measured'                 => $this->isMeasured(),
            'contextLength'            => $this->contextLength,
            'reservedOutput'           => $this->reservedOutput,
            'safetyMargin'             => $this->safetyMargin,
            'budget'                   => $this->budget,
            'transcriptTokens'         => $this->transcriptTokens,
            'toolSchemaTokens'         => $this->toolSchemaTokens,
            'systemPromptTokens'       => $this->systemPromptTokens,
            'systemPromptInTranscript' => $this->systemPromptInTranscript,
            'skillTokens'              => $this->skillTokens,
            'estimatedTokens'          => $this->estimatedTokens,
            'remaining'                => $this->remaining,
        ];
    }
}
