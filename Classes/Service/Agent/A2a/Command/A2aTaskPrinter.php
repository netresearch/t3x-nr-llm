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
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints a remote task for the A2A commands.
 *
 * Remote text is output of another system: it is printed as plain text and
 * never interpreted as console markup.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aTaskPrinter
{
    public static function print(SymfonyStyle $io, A2aTask $task): void
    {
        $io->definitionList(
            ['Task' => $task->id],
            ['Context' => $task->contextId !== '' ? $task->contextId : '-'],
            ['State' => $task->status->rawState . ($task->status->isKnown() ? '' : ' (not part of protocol 1.0)')],
            ['Status time' => $task->status->timestamp instanceof DateTimeImmutable ? A2aTimestamp::format($task->status->timestamp) : '-'],
        );

        $statusText = $task->status->message?->text() ?? '';
        if ($statusText !== '') {
            $io->writeln('Status message:');
            $io->writeln($statusText, SymfonyStyle::OUTPUT_RAW);
        }

        foreach ($task->artifacts as $artifact) {
            $io->writeln(sprintf('Artifact %s%s:', $artifact->artifactId, $artifact->name !== '' ? ' (' . $artifact->name . ')' : ''));
            $io->writeln($artifact->text() !== '' ? $artifact->text() : '(no text part)', SymfonyStyle::OUTPUT_RAW);
        }
    }
}
