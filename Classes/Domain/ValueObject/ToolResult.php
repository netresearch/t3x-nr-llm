<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\ToolOutcome;
use Netresearch\NrLlm\Domain\Enum\WriteCompleteness;
use Netresearch\NrLlm\Domain\Enum\WriteKind;

/**
 * The typed return value of {@see \Netresearch\NrLlm\Service\Tool\ToolInterface::execute()}.
 *
 * SECURITY INVARIANT — egress separation by construction:
 *   `$content` is the ONLY member that may cross the provider wire (it also
 *   egresses to the backend DOM). `$artifacts` are RUN-SCOPED: trace, inspector
 *   and persisted audit only. This class has NO __toString() and NO accessor
 *   that merges an artifact into a wire string, so `->content` is the single
 *   path to a wire string. Both channels are untrusted tool bytes; both are
 *   UTF-8-coerced and byte-bounded in {@see \Netresearch\NrLlm\Service\Tool\ToolLoopService}::invoke()
 *   before egress.
 *
 * `$writeTarget` is a third channel and a narrower one: an IDENTITY the runtime
 * itself derives meaning from (ADR-182). It never reaches the wire and never
 * carries a field value — it names the record a write produced so the run trace
 * can persist it and the observed outcome can join `sys_history` against it.
 * It is the one channel the loop does not bound on the way out, because
 * {@see RecordReference} refuses anything that is not a database identifier at
 * construction: there is no unbounded string to coerce.
 *
 * `$writeKind` travels with it and only with it: both are set by the same
 * method and by no other, so "a target without a kind" is not a state this
 * class can be in (ADR-187). `$writeCompleteness` is the third member of that
 * set (ADR-214): whether the write did everything the call planned, stated by
 * the tool per return. Null means "not stated", which a third-party tool that
 * calls {@see self::withWriteTarget()} without it still produces.
 *
 * `$hookFailedAfterWrite` is set by the tool loop, never by a tool: a
 * DataHandler hook of the installation failed after the last write of the call
 * (ADR-206). It is reported beside the completeness and does not change it.
 *
 * Fail-closed: {@see self::error()} carries NO artifacts and NO write target.
 *
 * @api
 */
final readonly class ToolResult
{
    /**
     * @param list<ToolArtifact> $artifacts
     */
    private function __construct(
        public string $content,
        public bool $isError,
        public array $artifacts,
        public ToolOutcome $outcome,
        public ?RecordReference $writeTarget = null,
        public ?WriteKind $writeKind = null,
        public ?WriteCompleteness $writeCompleteness = null,
        public bool $hookFailedAfterWrite = false,
    ) {}

    /**
     * A non-error result with optional run-only structured artifacts.
     */
    public static function text(string $content, ToolArtifact ...$artifacts): self
    {
        return new self($content, false, array_values($artifacts), ToolOutcome::OK);
    }

    /**
     * An error result. Fail-closed by construction: NO artifacts ever ride an
     * error result, so a failing tool cannot leak a half-built structure — and
     * no write target either, so a failed call can never claim a record.
     */
    public static function error(string $content): self
    {
        return new self($content, true, [], ToolOutcome::FAILED);
    }

    /**
     * A result the run's cancellation ended, not the tool (ADR-191, #774).
     *
     * Fail-closed exactly like {@see self::error()} -- `isError` is true, no
     * artifacts, no write target -- so every consumer that reads the boolean
     * keeps the meaning it had. What it adds is that the run inspector, and
     * anything counting failures per server, can tell an operator's cancel from
     * a fault. The server may have been perfectly healthy, and whether a remote
     * write landed is not knowable from here (ADR-190).
     */
    public static function cancelled(string $content): self
    {
        return new self($content, true, [], ToolOutcome::CANCELLED);
    }

    /**
     * The same result, naming the record this successful write produced
     * (ADR-182). Only a tool declaring a write effect may call it, and
     * `ToolEffectCoverageTest` requires that every such tool does.
     *
     * Refused on an error result: fail-closed, mirroring the artifact rule
     * above. A failing write must not report a target.
     *
     * The kind is required rather than defaulted, because a default would be a
     * guess about what the tool did, and the tool is the only party that knows
     * whether it minted the uid or was handed it. It is what
     * {@see \Netresearch\NrLlm\Event\AfterAiRecordWrittenEvent} reports to
     * consumers (ADR-187).
     *
     * The completeness says whether the write did everything the call planned
     * (ADR-214). Every builtin states it on every return, and a coverage test
     * refuses a builtin call that omits it or passes null. It defaults to null
     * only because this class is on the frozen surface (ADR-182): a required
     * argument would break every third-party tool that names its record, and
     * null — "not stated" — is what such a tool has always meant.
     */
    public function withWriteTarget(RecordReference $target, WriteKind $kind, ?WriteCompleteness $completeness = null): self
    {
        if ($this->isError) {
            return $this;
        }

        return new self($this->content, false, $this->artifacts, $this->outcome, $target, $kind, $completeness, $this->hookFailedAfterWrite);
    }

    /**
     * The same result, marked as one after whose last write a DataHandler
     * hook of the installation failed (ADR-206, ADR-214).
     *
     * Called by {@see \Netresearch\NrLlm\Service\Tool\ToolLoopService} where it
     * adds the note about that failure, from the same failure list, and by
     * nothing else. Every other member — the completeness the tool stated
     * included — is carried unchanged: the tool's statement about its plan
     * stays the tool's, and the flag is a second fact beside it.
     */
    public function withHookFailedAfterWrite(): self
    {
        // An error result names no write, so there is no write step to carry
        // the flag; fail-closed like the target and the artifacts.
        if ($this->isError) {
            return $this;
        }

        return new self(
            $this->content,
            $this->isError,
            $this->artifacts,
            $this->outcome,
            $this->writeTarget,
            $this->writeKind,
            $this->writeCompleteness,
            true,
        );
    }

    /**
     * The same result with its untrusted channels replaced by the bounded
     * forms, and EVERY other member carried forward unchanged.
     *
     * This method exists because {@see \Netresearch\NrLlm\Service\Tool\ToolLoopService}
     * used to rebuild the result from two properties, which silently dropped
     * anything added later (ADR-182 names the three values #844, #845 and #846
     * already lost to that shape). Bounding is a transformation of two members,
     * so it is expressed as one. It still builds the result by constructor
     * position, so a property added to this class is carried only when the
     * lines below pass it on — ADR-214 found the completeness and the hook flag
     * would have been dropped here. `ToolResultTest` asserts every member
     * survives.
     *
     * An error result stays empty of both side channels whatever the caller
     * supplies. {@see self::error()} and {@see self::withWriteTarget()} enforce
     * that at their own end; without it here, a public transformation could put
     * an artifact back on a failed call, and the fail-closed rule would hold by
     * caller discipline rather than by construction.
     *
     * @param list<ToolArtifact> $artifacts the bounded artifacts
     */
    public function withBoundedChannels(string $content, array $artifacts): self
    {
        if ($this->isError) {
            // No artifacts and — by the constructor's own default — no write
            // target either. Spelling the null out is redundant and Rector says
            // so; what matters is that the branch exists here rather than at
            // the call site.
            // The OUTCOME travels even here, where nothing else does: it is
            // the one member that says WHY the channels are empty, and
            // rebuilding it as FAILED would relabel an operator's cancel as a
            // fault on the one path every tool result passes through.
            return new self($content, true, [], $this->outcome);
        }

        return new self(
            $content,
            false,
            $artifacts,
            $this->outcome,
            $this->writeTarget,
            $this->writeKind,
            $this->writeCompleteness,
            $this->hookFailedAfterWrite,
        );
    }
}
