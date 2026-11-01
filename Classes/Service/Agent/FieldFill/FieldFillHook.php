<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\FieldFill;

use Netresearch\NrLlm\Service\Tool\Builtin\ToolDataHandler;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Queues the fill of an empty field after a save (ADR-237).
 *
 * The record is already written when this runs. Whatever goes wrong here is
 * logged and ends here, so an editor never loses or repeats a save over it.
 *
 * Core builds this hook for every save of the installation, and the planner
 * brings the whole agent runtime and every registered tool with it. So the
 * planner is asked for only when a field is opted in, and inside the guard.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class FieldFillHook
{
    /**
     * The most runs one request queues. A list edit can save hundreds of records
     * in one go, and an extension may do so through a DataHandler per record.
     */
    public const MAX_RUNS_PER_SAVE = 20;

    private int $queued = 0;

    public function __construct(
        private readonly FieldFillSettings $settings,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_afterDatabaseOperations(string $status, string $table, string|int $id, array $fieldArray, DataHandler $dataHandler): void
    {
        if ($this->settings->fieldsOf($table) === []) {
            return;
        }

        // The worker's own write, and every other AI write, is not an editor's
        // save. Neither is what core writes for a copy or a translation, through
        // a DataHandler of its own that leaves the text as it is: a copied page
        // tree would queue a run for every page in it.
        if ($dataHandler instanceof ToolDataHandler || $dataHandler->isImporting || $dataHandler->dontProcessTransformations) {
            return;
        }

        $uid = $status === 'new' ? ($dataHandler->substNEWwithIDs[$id] ?? 0) : $id;

        if (!is_numeric($uid) || $this->queued >= self::MAX_RUNS_PER_SAVE) {
            return;
        }

        try {
            $planner = GeneralUtility::makeInstance(FieldFillPlanner::class);

            $this->queued += $planner->afterSave($status, $table, (int) $uid, $fieldArray, $dataHandler->BE_USER);
        } catch (Throwable $exception) {
            $this->logger->warning('An empty field could not be queued for filling', ['table' => $table, 'uid' => $uid, 'exception' => $exception]);
        }
    }
}
