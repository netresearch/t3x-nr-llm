<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Doctrine\DBAL\Exception as DbalException;
use Netresearch\NrLlm\Utility\SecretShapeRedactorTrait;
use PDOException;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Throwable;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\DataHandling\ReferenceIndexUpdater;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The DataHandler every writing tool runs (ADR-206).
 *
 * A hook or listener of the installation runs inside every DataHandler run,
 * and one that throws used to end the tool call with "tool failed" — also
 * when every write of the run had landed. A translation extension that queues
 * a session flash message after each write does exactly that in any process
 * without a user session, the agent worker included. The model then retries a
 * write that is done.
 *
 * This subclass tells two failures of the OUTERMOST run apart by the method
 * that run called when it failed:
 *
 * - **After the writes** — in `processDatamap_afterAllOperations`, in
 *   `processCmdmap_afterFinish`, in the reference index update or in the cache
 *   flush. Every record of the run is written. The run is finished (the steps
 *   the failure skipped still run), the failure is logged, and a one-line
 *   description is recorded for the tool loop, which adds it to the tool's
 *   answer ({@see self::takeFailures()}). The tool reads back and answers as
 *   usual.
 * - **During the writes** — anywhere else, a nested run included: a copy or a
 *   translation core writes through a DataHandler of its own. Some records may
 *   be written and some not, and relations may still point at the source. The
 *   finishing steps run, so the pages written so far are flushed, and the
 *   failure is rethrown: "failed" is the honest answer, and a tool that read
 *   back half a copy would report a wrong one.
 *
 * The DataHandler's error log stays as TYPO3 wrote it. Thirteen writers refuse
 * as soon as that log is not empty; a failure written into it would turn a
 * write that landed into "refused".
 *
 * A run nested inside another DataHandler run rethrows whatever failed: the run
 * around it has its own finishing steps ahead, and the code that started that
 * run must see the failure.
 */
class ToolDataHandler extends DataHandler
{
    use SecretShapeRedactorTrait;

    /** How long one description may be, so a runaway message cannot flood the answer. */
    private const MAX_DESCRIPTION_LENGTH = 300;

    /**
     * The steps of an outermost run that come after its last write, by the
     * method the run calls for each, in the order the run calls them.
     */
    private const AFTER_THE_WRITES = [
        'processDatamap_afterAllOperations',
        'processCmdmap_afterFinish',
        ReferenceIndexUpdater::class . '::update',
        DataHandler::class . '::processClearCacheQueue',
    ];

    /**
     * Descriptions of the failures in this process that the tool loop has not
     * taken yet. Static because each tool builds its own DataHandler and the
     * loop never sees it; the loop takes them after every tool call that
     * returns, and empties the list before every call.
     *
     * @var list<string>
     */
    private static array $untakenFailures = [];

    /**
     * The descriptions recorded since the last call, and none after it.
     *
     * @return list<string>
     */
    public static function takeFailures(): array
    {
        $failures              = self::$untakenFailures;
        self::$untakenFailures = [];

        return $failures;
    }

    public function process_datamap(): void
    {
        try {
            parent::process_datamap();
        } catch (Throwable $failure) {
            $this->recover($failure, 'resetElementsToBeDeleted');
        }
    }

    /**
     * @return void|bool
     */
    public function process_cmdmap()
    {
        try {
            return parent::process_cmdmap();
        } catch (Throwable $failure) {
            $this->recover($failure, 'resetNestedElementCalls');
        }
    }

    /**
     * @param 'resetElementsToBeDeleted'|'resetNestedElementCalls' $reset the reset TYPO3 ends this kind of run with
     *
     * @throws Throwable the failure itself, unless it came after the writes of the outermost run
     */
    private function recover(Throwable $failure, string $reset): void
    {
        if (!$this->isOuterMostInstance()) {
            throw $failure;
        }

        $step = $this->stepThatFailed($failure);
        $this->logger()->error(
            'A DataHandler run of a tool threw; its finishing steps ran anyway.',
            ['exception' => $failure, 'afterTheWrites' => $step !== null],
        );

        $this->finish($step, $reset);

        if ($step === null) {
            throw $failure;
        }

        self::$untakenFailures[] = $this->describe($failure);
    }

    /**
     * Run what the outermost run has left to do after the step that failed:
     * the reference index update, the cache flush and the registry reset, as
     * far as they did not run yet. Each on its own, so one that fails does not
     * keep the next from running. A failure here is logged and not recorded:
     * the write it follows is done, and the note already names a failure.
     *
     * @param string|null                                          $failedStep one of {@see self::AFTER_THE_WRITES}, or null for a failure during the writes
     * @param 'resetElementsToBeDeleted'|'resetNestedElementCalls' $reset
     */
    private function finish(?string $failedStep, string $reset): void
    {
        $failedAt = $failedStep === null ? -1 : (int)array_search($failedStep, self::AFTER_THE_WRITES, true);

        $steps = [];
        if ($failedAt < 2) {
            $steps[] = $this->referenceIndexUpdater->update(...);
        }

        if ($failedAt < 3) {
            $steps[] = $this->processClearCacheQueue(...);
        } else {
            // The flush broke off before it emptied its queue. Run again, it
            // fails the same way; left as it is, the next run in this process
            // replays it (ADR-206).
            $steps[] = $this->forgetTheCacheQueue(...);
        }

        $steps[] = $reset === 'resetElementsToBeDeleted'
            ? $this->resetElementsToBeDeleted(...)
            : $this->resetNestedElementCalls(...);

        foreach ($steps as $step) {
            try {
                $step();
            } catch (Throwable $stepFailure) {
                $this->forgetTheCacheQueue();
                $this->logger()->error("A finishing step of a tool's DataHandler run threw.", ['exception' => $stepFailure]);
            }
        }
    }

    private function forgetTheCacheQueue(): void
    {
        static::$recordsToClearCacheFor      = [];
        static::$recordPidsForDeletedRecords = [];
    }

    /**
     * Which step after the writes the outermost run was in when it failed, or
     * null when it was still writing.
     *
     * A trace frame names the called method and the file it was called FROM.
     * The frame called from this file is the parent's `process_datamap()` or
     * `process_cmdmap()`; the frame just inside it is the method that run
     * called when it failed. A nested run shows up there as a
     * `process_datamap()` of its own, which is not a step after the writes.
     */
    private function stepThatFailed(Throwable $failure): ?string
    {
        $trace = $failure->getTrace();
        foreach ($trace as $index => $frame) {
            if (($frame['file'] ?? null) !== __FILE__) {
                continue;
            }

            // A failure thrown by the run's own method has no frame inside it.
            $called = $trace[$index - 1] ?? null;
            if ($called === null) {
                return null;
            }

            foreach ([$called['function'], ($called['class'] ?? '') . '::' . $called['function']] as $name) {
                if (in_array($name, self::AFTER_THE_WRITES, true)) {
                    return $name;
                }
            }

            return null;
        }

        return null;
    }

    /**
     * One line: which code failed, with what, bounded in length. A database
     * exception is named without its message, which can carry connection
     * details; any other message is stripped of every secret shape the
     * org-wide catalogue knows — credentials in URLs, bearer tokens, vendor
     * keys — because the line reaches the language model.
     */
    private function describe(Throwable $failure): string
    {
        $description = sprintf(
            '%s threw %s: %s',
            $this->failingCode($failure) ?? 'The DataHandler run',
            $failure::class,
            $this->isDatabaseFailure($failure)
                ? 'a database error, see the TYPO3 log'
                : $this->redactSecretShapes($failure->getMessage()),
        );

        return mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH
            ? mb_substr($description, 0, self::MAX_DESCRIPTION_LENGTH) . '…'
            : $description;
    }

    /**
     * The installation's code that failed, as `Class::method()`, or null when
     * the trace names none.
     *
     * The frame the DataHandler called is the hook method itself for a
     * `processDatamap_*` / `processCmdmap_*` hook. A hook called through
     * `GeneralUtility::callUserFunction()`, or a listener through the event
     * dispatcher, sits further inside, so the innermost frame outside TYPO3's
     * own namespace from there on is named; without one, the frame the
     * DataHandler called is.
     */
    private function failingCode(Throwable $failure): ?string
    {
        $dataHandlerFile = (new ReflectionClass(DataHandler::class))->getFileName();
        $trace           = $failure->getTrace();

        foreach ($trace as $index => $frame) {
            $class = $frame['class'] ?? null;
            if (($frame['file'] ?? null) !== $dataHandlerFile || !is_string($class) || is_a($class, DataHandler::class, true)) {
                continue;
            }

            for ($inner = $index; $inner >= 0; $inner--) {
                $innerClass = $trace[$inner]['class'] ?? null;
                if (is_string($innerClass) && !str_starts_with($innerClass, 'TYPO3\\CMS\\')) {
                    return $innerClass . '::' . $trace[$inner]['function'] . '()';
                }
            }

            return $class . '::' . $frame['function'] . '()';
        }

        return null;
    }

    private function isDatabaseFailure(Throwable $failure): bool
    {
        for ($cause = $failure; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof DbalException || $cause instanceof PDOException) {
                return true;
            }
        }

        return false;
    }

    private function logger(): LoggerInterface
    {
        return GeneralUtility::makeInstance(LogManager::class)->getLogger(self::class);
    }
}
