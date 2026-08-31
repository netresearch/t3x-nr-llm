<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

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
        public ?RecordReference $writeTarget = null,
    ) {}

    /**
     * A non-error result with optional run-only structured artifacts.
     */
    public static function text(string $content, ToolArtifact ...$artifacts): self
    {
        return new self($content, false, array_values($artifacts));
    }

    /**
     * An error result. Fail-closed by construction: NO artifacts ever ride an
     * error result, so a failing tool cannot leak a half-built structure — and
     * no write target either, so a failed call can never claim a record.
     */
    public static function error(string $content): self
    {
        return new self($content, true, []);
    }

    /**
     * The same result, naming the record this successful write produced
     * (ADR-182). Only a tool declaring a write effect may call it, and
     * `ToolEffectCoverageTest` requires that every such tool does.
     *
     * Refused on an error result: fail-closed, mirroring the artifact rule
     * above. A failing write must not report a target.
     */
    public function withWriteTarget(RecordReference $target): self
    {
        if ($this->isError) {
            return $this;
        }

        return new self($this->content, false, $this->artifacts, $target);
    }

    /**
     * The same result with its untrusted channels replaced by the bounded
     * forms, and EVERY other member carried forward unchanged.
     *
     * This method exists because {@see \Netresearch\NrLlm\Service\Tool\ToolLoopService}
     * used to rebuild the result from two properties, which silently dropped
     * anything added later (ADR-182 names the three values #844, #845 and #846
     * already lost to that shape). Bounding is a transformation of two members,
     * so it is expressed as one — a property added to this class is carried by
     * default instead of being dropped by default.
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
            return new self($content, true, []);
        }

        return new self($content, false, $artifacts, $this->writeTarget);
    }
}
