<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Streaming;

use Closure;
use Netresearch\NrLlm\Service\Streaming\StreamRedactionWindow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The golden invariant of the sliding window is: concatenating every emitted
 * delta equals redacting the whole raw stream in one shot. If the prune/offset
 * bookkeeping is wrong anywhere, this breaks. A randomised property test asserts
 * it over hundreds of secret/benign/multibyte inputs and chunk splittings; the
 * named cases pin the specific adversarial scenarios (secret past the old cap,
 * boundary + prune-boundary splits, a >1 MB unbroken run, multibyte).
 */
#[CoversClass(StreamRedactionWindow::class)]
final class StreamRedactionWindowTest extends TestCase
{
    /**
     * A representative redactor with the ADR-088 property (a complete match
     * collapses to a fixed short marker) — the same shape as the shipped
     * SecretRedactionGuardrail patterns.
     *
     * @return Closure(string): string
     */
    private function redactor(): Closure
    {
        return static function (string $s): string {
            // Null-guard each preg_replace (a backtrack-limit failure returns null,
            // which a bare (string) cast would silently wipe to '') — mirroring the
            // production RedactsSecretsTrait.
            foreach ([
                ['/([?&])(key|token|secret)=[^&\s]+/i', '$1$2=***'],
                ['/\bsk-[A-Za-z0-9_-]{16,}/', 'sk-***'],
                ['/\b(Bearer\s+)[A-Za-z0-9._~+\/-]+=*/i', '$1***'],
            ] as [$pattern, $replacement]) {
                $replaced = preg_replace($pattern, $replacement, $s);
                if (is_string($replaced)) {
                    $s = $replaced;
                }
            }

            return $s;
        };
    }

    /**
     * Feed $raw through the window in $chunkSize-byte pieces; return concatenated deltas.
     */
    private function stream(StreamRedactionWindow $w, string $raw, int $chunkSize): string
    {
        $out = '';
        for ($i = 0, $n = \strlen($raw); $i < $n; $i += $chunkSize) {
            foreach ($w->push(\substr($raw, $i, $chunkSize)) as $d) {
                $out .= $d;
            }
        }
        foreach ($w->flush() as $d) {
            $out .= $d;
        }

        return $out;
    }

    #[Test]
    public function concatenatedOutputEqualsFullRedactionForAssortedInputsAndChunkSizes(): void
    {
        $redact = $this->redactor();
        $cases  = [
            'benign'                 => str_repeat('the quick brown fox ', 50),
            'secret past 8k window'  => str_repeat('word ', 2000) . 'sk-ABCDEFGHIJKLMNOP0123 tail',
            'secret at the start'    => 'sk-ABCDEFGHIJKLMNOP0123 then ' . str_repeat('x ', 5000),
            'bearer with spaces'     => 'Authorization: Bearer    tokAbc123def456 end',
            'url param'              => 'GET /x?key=SUPERSECRETVALUE&z=1 ok',
            'interleaved secrets'    => str_repeat('a sk-ABCDEFGHIJKLMNOP01 b Bearer tok12345678 c ?token=zzzz d ', 300),
        ];
        foreach ($cases as $name => $raw) {
            $expected = $redact($raw);
            foreach ([1, 3, 7, 64, 257, 1000] as $chunk) {
                $out = $this->stream(new StreamRedactionWindow($redact), $raw, $chunk);
                self::assertSame($expected, $out, "case={$name} chunk={$chunk}");
            }
        }
    }

    /** Deterministic LCG state, so the property test is reproducible (and lint-stable). */
    private int $rng = 20260719;

    private function roll(int $lo, int $hi): int
    {
        $this->rng = (($this->rng * 1103515245) + 12345) & 0x7FFFFFFF;

        return $lo + ($this->rng % (($hi - $lo) + 1));
    }

    #[Test]
    public function propertyConcatEqualsFullRedactionOverRandomisedInputs(): void
    {
        $redact    = $this->redactor();
        $this->rng = 20260719;
        for ($iter = 0; $iter < 300; ++$iter) {
            $raw      = $this->randomRaw();
            $expected = $redact($raw);
            $chunk    = $this->roll(1, 20);
            $out      = $this->stream(new StreamRedactionWindow($redact), $raw, $chunk);
            self::assertSame($expected, $out, "iter={$iter} chunk={$chunk} rawLen=" . \strlen($raw));
        }
    }

    private function randomRaw(): string
    {
        $parts = [];
        $n     = $this->roll(5, 60);
        for ($i = 0; $i < $n; ++$i) {
            $parts[] = match ($this->roll(0, 9)) {
                0, 1, 2, 3, 4 => str_repeat('word', $this->roll(1, 400)) . ' ', // benign, sometimes crosses SOFT_CAP
                5             => 'sk-' . $this->randAlnum($this->roll(16, 40)) . ' ',
                6             => 'Bearer ' . $this->randAlnum($this->roll(8, 30)) . ' ',
                7             => '?key=' . $this->randAlnum($this->roll(3, 20)) . ' ',
                8             => 'grüße-😀-мир ',                               // multibyte
                default       => "plain text bit {$i} ",
            };
        }

        return implode('', $parts);
    }

    private function randAlnum(int $len): string
    {
        $c = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $s = '';
        for ($i = 0; $i < $len; ++$i) {
            $s .= $c[$this->roll(0, 61)];
        }

        return $s;
    }

    #[Test]
    public function secretPositionedPastTheOldFiftyKbCapIsStillMasked(): void
    {
        $redact = $this->redactor();
        $raw    = str_repeat("benign line number here\n", 3000) // > 60 KB of filler
            . ' the key is sk-ABCDEFGHIJKLMNOP0123456789 done';

        $out = $this->stream(new StreamRedactionWindow($redact), $raw, 1);

        self::assertStringContainsString('sk-***', $out);
        self::assertStringNotContainsString('sk-ABCDEFGHIJKLMNOP0123456789', $out);
        self::assertSame($redact($raw), $out);
    }

    #[Test]
    public function memoryStaysBoundedAndPruningActuallyCommitsOnALongStream(): void
    {
        $w   = new StreamRedactionWindow($this->redactor());
        $max = 0;
        for ($i = 0; $i < 5000; ++$i) {
            $w->push('some benign words here and there ');
            $max = max($max, $w->currentWindowBytes());
        }
        $w->flush();

        self::assertLessThan(20000, $max, 'window is pruned well below the ~165 KB raw stream');
        self::assertGreaterThan(0, $w->prunedRedactedBytes(), 'a prune actually committed');
    }

    #[Test]
    public function singleUnbrokenSecretRunLongerThanHardCapEmitsOneMarkerAndNeverLeaksRaw(): void
    {
        $redact = $this->redactor();
        $w      = new StreamRedactionWindow($redact);
        $raw    = 'sk-' . str_repeat('A', 2_000_000); // 2 MB unbroken class run
        $out    = '';
        foreach ($w->push($raw) as $d) {
            $out .= $d;
        }
        foreach ($w->push(' done') as $d) {
            $out .= $d;
        }
        foreach ($w->flush() as $d) {
            $out .= $d;
        }

        self::assertStringNotContainsString('AAAA', $out, 'no raw secret byte is ever emitted');
        self::assertStringContainsString('sk-***', $out);
        self::assertLessThan(2_000_000, $w->currentWindowBytes(), 'window stayed bounded (middle dropped)');
        self::assertSame($redact($raw . ' done'), $out);
    }

    #[Test]
    public function benignUnbrokenBlobLargerThanHardCapPassesThroughIntact(): void
    {
        $w    = new StreamRedactionWindow($this->redactor());
        $blob = str_repeat('B', 1_500_000); // no whitespace, no secret
        $out  = '';
        foreach ($w->push($blob) as $d) {
            $out .= $d;
        }
        foreach ($w->flush() as $d) {
            $out .= $d;
        }

        self::assertSame($blob, $out, 'identity redaction factorises, so a benign blob is not truncated');
    }

    #[Test]
    public function emittedDeltaBoundariesNeverSplitAUtf8Codepoint(): void
    {
        $redact = $this->redactor();
        $raw    = str_repeat('grüße 😀 мир ', 2000); // heavy multibyte, crosses the caps
        $w      = new StreamRedactionWindow($redact);
        $out    = '';
        for ($i = 0, $n = \strlen($raw); $i < $n; $i += 3) {
            foreach ($w->push(\substr($raw, $i, 3)) as $d) {
                $out .= $d;
                self::assertTrue(mb_check_encoding($out, 'UTF-8'), 'no delta boundary splits a codepoint');
            }
        }
        foreach ($w->flush() as $d) {
            $out .= $d;
        }

        self::assertSame($redact($raw), $out);
    }
}
