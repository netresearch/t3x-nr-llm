<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use JsonException;
use Netresearch\NrLlm\Domain\Enum\ArtifactType;
use Netresearch\NrLlm\Domain\ValueObject\ToolArtifact;

/**
 * Bounds untrusted tool output before it leaves the loop, extracted verbatim
 * from ToolLoopService.
 *
 * Tool results are untrusted bytes and cross two boundaries — the provider
 * wire (content) and the trace/inspector/persisted stream (artifacts). Each
 * channel has its own independent byte cap, and every string is coerced to
 * valid UTF-8 first so neither boundary can crash on a misbehaving tool.
 *
 * Stateless and pure: no collaborator, no logger, no policy branch. It is
 * instantiated as a defaulted, NON-nullable constructor argument of the loop
 * — the JsonSchemaValidator pattern from ADR-120 — so there is no wiring
 * under which the bound can be absent. invoke() remains the single seam that
 * applies it (ADR-108); this class only owns the how, never the where.
 */
final readonly class ToolResultBounder
{
    /**
     * Hard cap on a single tool result appended to the message list. A buggy or
     * malicious tool returning multi-megabyte output would otherwise blow the
     * provider payload limit, bypass the token budget and pressure memory.
     */
    private const MAX_TOOL_RESULT_BYTES = 50000;

    /**
     * Hard cap on the total serialised bytes of a single tool call's artifacts.
     * Independent of {@see self::MAX_TOOL_RESULT_BYTES}: content and artifacts
     * are separate egress channels, so each is bounded on its own so a large one
     * cannot mask an over-budget other. The rationale is crash-safety and
     * DOM/persistence size, NOT provider-context starvation — artifacts have no
     * provider path, so they can never starve model-visible content.
     */
    private const MAX_TOOL_ARTIFACT_BYTES = 50000;

    /**
     * Bound and sanitise a tool's artifacts before they enter the trace /
     * inspector / persisted stream. UTF-8-coerces every string leaf (reusing the
     * same seam that makes untrusted tool bytes JSON-safe for `content`), then
     * validates the whole list with the EXACT json_encode flags AND a depth cap
     * the downstream sinks use ({@see \Netresearch\NrLlm\Controller\Backend\ToolPlaygroundController}
     * and {@see AgentRunPersister::recordStep()} all encode with
     * JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE). Anything that survives
     * here therefore CANNOT throw at those sinks — crash-safety by construction,
     * not by a lenient superset.
     *
     * Fail-closed: on a JsonException (non-finite float, unencodable type,
     * over-depth from a corrupt/cyclic structure) or an over-budget encode, the
     * WHOLE list is replaced by one TEXT marker — never a mid-structure
     * truncation.
     *
     * @param list<ToolArtifact> $artifacts
     *
     * @return list<ToolArtifact>
     */
    public function artifacts(array $artifacts): array
    {
        if ($artifacts === []) {
            return [];
        }

        $coerced = array_map(
            fn(ToolArtifact $a): ToolArtifact => new ToolArtifact(
                $a->type,
                $this->toValidUtf8($a->label),
                $this->coerceLeaves($a->data),
            ),
            $artifacts,
        );

        try {
            // Depth 64 leaves ample headroom below json_encode's default 512 so
            // an artifact that encodes here also encodes when the sink nests it a
            // few levels deeper inside the RunStep payload. A legitimate TABLE
            // nests ~4 deep; a malicious deep structure trips the fail-closed
            // marker.
            $json = json_encode(
                array_map(static fn(ToolArtifact $a): array => $a->toArray(), $coerced),
                JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
                64,
            );
        } catch (JsonException) {
            return [$this->artifactsOmitted('could not be encoded')];
        }

        if (strlen($json) > self::MAX_TOOL_ARTIFACT_BYTES) {
            return [$this->artifactsOmitted('exceeded ' . self::MAX_TOOL_ARTIFACT_BYTES . ' bytes')];
        }

        return array_values($coerced);
    }

    /**
     * Recursively coerce every string leaf of an artifact payload to valid UTF-8,
     * reusing {@see self::toValidUtf8()}. `array<array-key, mixed>` (not
     * `array<string, mixed>`): a TABLE's `rows` are int-keyed sub-arrays.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function coerceLeaves(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $out[$key] = $this->toValidUtf8($value);
            } elseif (is_array($value)) {
                $out[$key] = $this->coerceLeaves($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function artifactsOmitted(string $reason): ToolArtifact
    {
        return new ToolArtifact(ArtifactType::TEXT, 'Artifacts omitted', ['text' => $reason]);
    }

    /**
     * Bound a tool result to {@see self::MAX_TOOL_RESULT_BYTES}. Uses mb_strcut
     * so the byte cap never splits a multibyte character (which would corrupt
     * the later JSON encoding), and appends a visible truncation marker.
     */
    public function content(string $result): string
    {
        // Tool output is untrusted bytes (logs, phpinfo, env, DB rows) and may
        // not be valid UTF-8. It is appended to the message list and re-encoded
        // as JSON on the next provider request — and serialised into the
        // inspector trace — where a single invalid byte makes json_encode()
        // throw a JsonException ("Malformed UTF-8"). Coerce to valid UTF-8 first
        // so neither path can crash on a misbehaving tool.
        $result = $this->toValidUtf8($result);

        if (strlen($result) <= self::MAX_TOOL_RESULT_BYTES) {
            return $result;
        }

        // Reserve the marker's bytes from the budget so the returned string
        // (content + marker) never exceeds the cap. mb_strcut with an explicit
        // UTF-8 encoding cuts on a byte boundary without splitting a character.
        $marker = "\n…[tool result truncated at " . self::MAX_TOOL_RESULT_BYTES . ' bytes]';
        $budget = self::MAX_TOOL_RESULT_BYTES - strlen($marker);

        return mb_strcut($result, 0, max(0, $budget), 'UTF-8') . $marker;
    }

    /**
     * Coerce a byte string to valid UTF-8, replacing invalid sequences (the
     * substitution is visible in the inspector rather than silently dropped).
     * A no-op for already-valid input.
     */
    private function toValidUtf8(string $result): string
    {
        if (mb_check_encoding($result, 'UTF-8')) {
            return $result;
        }

        // No cast needed: with literal 'UTF-8' encoding names PHPStan narrows
        // mb_convert_encoding()'s string|false to string — the false branch is
        // unreachable, and level 10 verifies exactly that.
        return mb_convert_encoding($result, 'UTF-8', 'UTF-8');
    }
}
