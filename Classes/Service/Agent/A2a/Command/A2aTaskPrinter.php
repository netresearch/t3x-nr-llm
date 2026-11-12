<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Command;

use DateTimeImmutable;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTask;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTimestamp;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints a remote task for the A2A commands.
 *
 * Every string the remote agent sent is untrusted: {@see self::plain()}
 * removes control and format characters (an ANSI escape sequence starts with
 * one, a bidi override reorders what follows) and
 * escapes Symfony's console markup, so a remote cannot restyle, overwrite or
 * hide output on the operator's terminal.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aTaskPrinter
{
    /**
     * A remote string made safe for the console: invalid UTF-8 replaced,
     * control and format characters other than the newline removed, `<`
     * markup escaped.
     */
    public static function plain(string $remote): string
    {
        // Valid UTF-8 first, so the /u pattern sees characters: then every
        // control character (C0, DEL and C1 such as U+009B, a CSI) and every
        // format character (bidi overrides such as U+202E) goes, the line
        // break excepted.
        return OutputFormatter::escape((string) preg_replace('/(?!\n)[\p{Cc}\p{Cf}]/u', '', mb_scrub($remote, 'UTF-8')));
    }

    /**
     * A remote identifier, state or name on one line: {@see self::plain()}
     * with the line break removed as well, so a remote id cannot start a line
     * of its own that reads like the command's output.
     */
    public static function line(string $remote): string
    {
        return str_replace("\n", '', self::plain($remote));
    }

    public static function print(SymfonyStyle $io, A2aTask $task): void
    {
        $io->definitionList(
            ['Task' => self::line($task->id)],
            ['Context' => $task->contextId !== '' ? self::line($task->contextId) : '-'],
            ['State' => self::line($task->status->rawState) . ($task->status->isKnown() ? '' : ' (not part of protocol 1.0)')],
            ['Status time' => $task->status->timestamp instanceof DateTimeImmutable ? A2aTimestamp::format($task->status->timestamp) : '-'],
        );

        $statusText = $task->status->message?->text() ?? '';
        if ($statusText !== '') {
            $io->writeln('Status message:');
            $io->writeln(self::plain($statusText));
        }

        foreach ($task->artifacts as $artifact) {
            $io->writeln(sprintf('Artifact %s%s:', self::line($artifact->artifactId), $artifact->name !== '' ? ' (' . self::line($artifact->name) . ')' : ''));
            $io->writeln($artifact->text() !== '' ? self::plain($artifact->text()) : '(no text part)');
        }
    }
}
