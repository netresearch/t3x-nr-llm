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
 * removes control characters (an ANSI escape sequence starts with one) and
 * escapes Symfony's console markup, so a remote cannot restyle, overwrite or
 * hide output on the operator's terminal.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aTaskPrinter
{
    /**
     * A remote string made safe for the console: control characters other
     * than the newline removed, `<` markup escaped.
     */
    public static function plain(string $remote): string
    {
        return OutputFormatter::escape((string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', $remote));
    }

    public static function print(SymfonyStyle $io, A2aTask $task): void
    {
        $io->definitionList(
            ['Task' => self::plain($task->id)],
            ['Context' => $task->contextId !== '' ? self::plain($task->contextId) : '-'],
            ['State' => self::plain($task->status->rawState) . ($task->status->isKnown() ? '' : ' (not part of protocol 1.0)')],
            ['Status time' => $task->status->timestamp instanceof DateTimeImmutable ? A2aTimestamp::format($task->status->timestamp) : '-'],
        );

        $statusText = $task->status->message?->text() ?? '';
        if ($statusText !== '') {
            $io->writeln('Status message:');
            $io->writeln(self::plain($statusText));
        }

        foreach ($task->artifacts as $artifact) {
            $io->writeln(sprintf('Artifact %s%s:', self::plain($artifact->artifactId), $artifact->name !== '' ? ' (' . self::plain($artifact->name) . ')' : ''));
            $io->writeln($artifact->text() !== '' ? self::plain($artifact->text()) : '(no text part)');
        }
    }
}
