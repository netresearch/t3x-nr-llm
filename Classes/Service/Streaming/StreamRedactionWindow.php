<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Streaming;

use Closure;

/**
 * Bounded, self-certifying sliding window for live stream redaction (ADR-088 /
 * ADR-088 overflow closure).
 *
 * {@see StreamingDispatcher} masks secrets in a streamed response by re-redacting
 * the accumulated raw text FRESH each chunk (so a complete match always collapses
 * to its short marker, even one split across chunk boundaries) and emitting only
 * the stable prefix — everything but a {@see self::HOLDBACK_BYTES} tail that may
 * still contain a match in progress.
 *
 * The naive version keeps the whole raw buffer, which is unbounded in memory and
 * O(n²) in per-chunk rescans; the original guard flushed at 50 KB and then passed
 * every later chunk through RAW, leaking a secret positioned past the cap. This
 * class removes both problems without ever emitting a raw secret byte:
 *
 * - It re-redacts only a bounded WINDOW (coalescing the rescan into ~256-byte
 *   blocks → O(n) work, not O(n²)).
 * - It prunes the settled front of the window at a cut it CERTIFIES clean:
 *   `redact(head) . redact(tail) === redact(window)` (no match spans the cut) and
 *   the dropped redaction is already emitted. Only then is the emit offset
 *   adjusted, so the concatenated output stays byte-for-byte equal to
 *   `redact(fullRawStream)`.
 * - For the pathological case of a single unbroken match longer than the window
 *   (no clean cut anywhere), it drops the middle of that match — but ONLY when
 *   re-redaction proves the drop leaves the output identical, so the offset stays
 *   valid. The dropped bytes are inside the marker-collapsed match and never
 *   belonged in the output; they are never emitted raw.
 *
 * Correctness is verified in isolation ({@see \Netresearch\NrLlm\Tests\Unit\Service\Streaming\StreamRedactionWindowTest}):
 * a randomised property test asserts `concat(deltas) === redact(fullRaw)` over
 * thousands of secret/benign/multibyte inputs and chunk splittings.
 */
final class StreamRedactionWindow
{
    /**
     * Trailing redacted bytes held back from the live stream so a match still in
     * progress at the tail is never emitted before it completes. A confirmed
     * match collapses to a short marker, so the only unstable region is a partial
     * anchor (``sk-`` + 15, ``Bearer `` + 7, a URL-param name + 1) — well under
     * this window.
     */
    public const HOLDBACK_BYTES = 128;

    /** Minimum bytes kept behind any prune cut (>= holdback + margin). */
    private const KEEP_BYTES = 512;

    /** Prune once the window grows past this. */
    private const SOFT_CAP_BYTES = 8192;

    /** Safe-hold (drop the middle of an unbroken match) past this. */
    private const HARD_CAP_BYTES = 1048576;

    /** Re-redact/emit only after this many raw bytes accumulate (rescan coalescing). */
    private const BLOCK_BYTES = 256;

    /** Bounded number of cut positions probed per prune. */
    private const PRUNE_PROBES = 8;

    /** Step (bytes) between probed cut positions. */
    private const PROBE_STEP = 64;

    private string $window = '';

    /** Redacted bytes of redact($window) already yielded. */
    private int $emitted = 0;

    /** Raw bytes accumulated since the last emit (coalescing counter). */
    private int $pending = 0;

    /** Total redacted bytes dropped by prune/hold (observability / test spy). */
    private int $prunedRedactedBytes = 0;

    /**
     * @param Closure(string): string $redact re-redacts a raw fragment; must
     *                                        collapse a complete match to a fixed
     *                                        short marker (the ADR-088 property)
     */
    public function __construct(private readonly Closure $redact) {}

    /**
     * Feed one raw chunk; return the redacted deltas to yield (possibly none).
     *
     * @return list<string>
     */
    public function push(string $chunk): array
    {
        $this->window .= $chunk;
        $this->pending += \strlen($chunk);
        if ($this->pending < self::BLOCK_BYTES) {
            return [];
        }
        $this->pending = 0;
        $out           = $this->emitStable(true);
        $this->bound();

        return $out;
    }

    /**
     * Flush the redacted remainder at end of stream (no holdback).
     *
     * @return list<string>
     */
    public function flush(): array
    {
        return $this->emitStable(false);
    }

    /**
     * Total redacted bytes pruned from the front so far (a test/observability spy
     * that a prune actually committed).
     */
    public function prunedRedactedBytes(): int
    {
        return $this->prunedRedactedBytes;
    }

    /**
     * Current retained raw window size in bytes (observability / test bound).
     */
    public function currentWindowBytes(): int
    {
        return \strlen($this->window);
    }

    /**
     * @return list<string>
     */
    private function emitStable(bool $holdback): array
    {
        $full  = ($this->redact)($this->window);
        $limit = $holdback
            ? $this->utf8SafeBack($full, \strlen($full) - self::HOLDBACK_BYTES)
            : \strlen($full);
        if ($limit <= $this->emitted) {
            return [];
        }
        $delta         = \substr($full, $this->emitted, $limit - $this->emitted);
        $this->emitted = $limit;

        return $delta === '' ? [] : [$delta];
    }

    private function bound(): void
    {
        if (\strlen($this->window) <= self::SOFT_CAP_BYTES) {
            return;
        }
        if ($this->pruneFront()) {
            return;
        }
        if (\strlen($this->window) >= self::HARD_CAP_BYTES) {
            $this->safeHold();
        }
    }

    /**
     * Drop a settled front prefix at a CERTIFIED-clean cut. The cut is committed
     * only if re-redacting the two halves reproduces the whole redaction byte for
     * byte (so no match spans the cut) AND the dropped redaction is already
     * emitted — then the emit offset adjustment is exact.
     */
    private function pruneFront(): bool
    {
        $full   = ($this->redact)($this->window);
        $cutMax = $this->utf8SafeBack($this->window, \strlen($this->window) - self::KEEP_BYTES);
        for ($i = 0; $i < self::PRUNE_PROBES; ++$i) {
            $cut = $this->utf8SafeBack($this->window, $cutMax - ($i * self::PROBE_STEP));
            if ($cut <= 0) {
                break;
            }
            $head  = \substr($this->window, 0, $cut);
            $rHead = ($this->redact)($head);
            if (\strlen($rHead) <= $this->emitted
                && $full === $rHead . ($this->redact)(\substr($this->window, $cut))) {
                $this->window              = \substr($this->window, $cut);
                $this->emitted             -= \strlen($rHead);
                $this->prunedRedactedBytes += \strlen($rHead);

                return true;
            }
        }

        return false;
    }

    /**
     * Fallback for a single unbroken match filling the whole window (no clean cut
     * anywhere): drop the middle, keeping the anchoring head and the recent tail —
     * but ONLY when re-redaction proves the drop leaves the output byte-identical,
     * so the emit offset stays valid and no unmasked byte is ever emitted. The
     * dropped middle is inside the marker-collapsed match and never belonged in
     * the output.
     */
    private function safeHold(): void
    {
        $keep = self::KEEP_BYTES;
        if (\strlen($this->window) <= 2 * $keep) {
            return;
        }
        $headLen   = $this->utf8SafeBack($this->window, $keep);
        $tailStart = $this->utf8SafeForward($this->window, \strlen($this->window) - $keep);
        $candidate = \substr($this->window, 0, $headLen) . \substr($this->window, $tailStart);
        if (($this->redact)($candidate) === ($this->redact)($this->window)) {
            $this->window = $candidate;
        }
    }

    /**
     * Back a byte length off any UTF-8 continuation run so it never lands inside a
     * multibyte sequence (continuation bytes are 10xxxxxx / 0x80–0xBF).
     */
    private function utf8SafeBack(string $text, int $length): int
    {
        if ($length <= 0) {
            return 0;
        }
        if ($length >= \strlen($text)) {
            return \strlen($text);
        }
        while ($length > 0 && (\ord($text[$length]) & 0xC0) === 0x80) {
            --$length;
        }

        return $length;
    }

    /**
     * Advance an offset FORWARD off any UTF-8 continuation run (for a tail start).
     */
    private function utf8SafeForward(string $text, int $offset): int
    {
        if ($offset <= 0) {
            return 0;
        }
        if ($offset >= \strlen($text)) {
            return \strlen($text);
        }
        while ($offset < \strlen($text) && (\ord($text[$offset]) & 0xC0) === 0x80) {
            ++$offset;
        }

        return $offset;
    }
}
