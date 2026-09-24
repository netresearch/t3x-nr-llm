<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Closure;
use Doctrine\DBAL\Exception as DbalException;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use PDOException;
use ReflectionClass;
use Throwable;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The DataHandler every writing tool runs (ADR-206).
 *
 * A hook or listener of the installation runs inside every DataHandler run,
 * and one that throws used to end the tool call with "tool failed" — also
 * when the write it ran after had landed. A translation extension that queues
 * a session flash message after each write does exactly that in any process
 * without a user session, the agent worker included. The model then retries a
 * write that is done.
 *
 * This subclass catches such a failure in the OUTERMOST run and does three
 * things instead of letting it end the call:
 *
 * - it runs the steps TYPO3 runs after the hooks of an outermost run and the
 *   failure skipped: the reference index update, the cache flush of every
 *   written page, and the reset of the run's registry — each on its own, so
 *   one that fails does not keep the others from running;
 * - it logs the failure with its trace;
 * - it records a one-line description for the tool loop, which adds it to the
 *   tool's answer ({@see self::takeFailures()}).
 *
 * The DataHandler's error log stays as TYPO3 wrote it. Several writers refuse
 * as soon as that log is not empty; a hook failure written into it would turn
 * a write that landed into "refused". With the log untouched, each writer's
 * own read-back decides what was written, as it does for any other outcome.
 *
 * A run nested inside another DataHandler run rethrows: the run around it
 * still has its own finishing steps ahead, and the code that started that run
 * must see the failure.
 *
 * NOT caught: anything outside `process_datamap()` and `process_cmdmap()`.
 * `start()` runs no hooks.
 */
class ToolDataHandler extends DataHandler
{
    use ErrorMessageSanitizerTrait;

    /** How long one description may be, so a runaway message cannot flood the answer. */
    private const MAX_DESCRIPTION_LENGTH = 300;

    /**
     * Descriptions of the failures in this process that the tool loop has not
     * taken yet. Static because each tool builds its own DataHandler and the
     * loop never sees it; the loop empties the list before and after every
     * tool call.
     *
     * @var list<string>
     */
    private static array $untakenFailures = [];

    /**
     * The descriptions of this instance's runs, for a tool that reports on
     * one run itself.
     *
     * @var list<string>
     */
    private array $failures = [];

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

    /**
     * The descriptions of this instance's failed runs, oldest first. Unlike
     * {@see self::takeFailures()} it takes nothing away from the tool loop.
     *
     * @return list<string>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    public function process_datamap(): void
    {
        try {
            parent::process_datamap();
        } catch (Throwable $failure) {
            $this->recover($failure, fn() => $this->resetElementsToBeDeleted());
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
            $this->recover($failure, fn() => $this->resetNestedElementCalls());
        }
    }

    /**
     * @param Closure(): void $resetRegistry the reset TYPO3 ends this kind of run with
     *
     * @throws Throwable the failure itself, when this run is nested in another one
     */
    private function recover(Throwable $failure, Closure $resetRegistry): void
    {
        if (!$this->isOuterMostInstance()) {
            throw $failure;
        }

        $this->record($failure);

        foreach ([
            $this->referenceIndexUpdater->update(...),
            $this->processClearCacheQueue(...),
            $resetRegistry,
        ] as $step) {
            try {
                $step();
            } catch (Throwable $stepFailure) {
                // The same installation code can fail again here: a cache
                // flush runs its own hooks.
                $this->record($stepFailure);
            }
        }
    }

    private function record(Throwable $failure): void
    {
        GeneralUtility::makeInstance(LogManager::class)->getLogger(self::class)->error(
            'A DataHandler run of a tool threw; its finishing steps ran anyway.',
            ['exception' => $failure],
        );

        $description = sprintf(
            '%s threw %s: %s',
            $this->failingCallee($failure) ?? 'The DataHandler run',
            $failure::class,
            $this->isDatabaseFailure($failure)
                // A database message can carry connection details.
                ? 'a database error, see the TYPO3 log'
                : $this->sanitizeErrorMessage($failure->getMessage()),
        );

        $description = mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH
            ? mb_substr($description, 0, self::MAX_DESCRIPTION_LENGTH) . '…'
            : $description;

        $this->failures[]        = $description;
        self::$untakenFailures[] = $description;
    }

    /**
     * The method the DataHandler called when the failure happened — a hook
     * method, as a rule — or null when the trace holds no such frame.
     *
     * A trace frame names the called method and the file it was called FROM,
     * so the frame whose call site lies in the DataHandler's own file and whose
     * method is not the DataHandler's is the one the DataHandler handed control
     * to.
     */
    private function failingCallee(Throwable $failure): ?string
    {
        $dataHandlerFile = (new ReflectionClass(DataHandler::class))->getFileName();

        foreach ($failure->getTrace() as $frame) {
            $class = $frame['class'] ?? null;
            if (($frame['file'] ?? null) === $dataHandlerFile && is_string($class) && !is_a($class, DataHandler::class, true)) {
                return $class . '::' . $frame['function'] . '()';
            }
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
}
