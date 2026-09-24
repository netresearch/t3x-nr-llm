<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\DataHandler;

use RuntimeException;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A DataHandler hook of an installation that throws, chosen per test through
 * the static switch below; the test resets it in tearDown.
 *
 * - `AFTER_ALL_OPERATIONS` and `AFTER_FINISH` queue a flash message for the
 *   session once the write is done, the way a translation extension reports
 *   a failed translation. A process whose ambient backend user has no session
 *   — every CLI process, the agent worker among them — fails there with
 *   `Call to a member function set() on null`, because
 *   `AbstractUserAuthentication::setAndSaveSessionData()` does not check for
 *   one. That is the failure that took `publish_record` down on a live
 *   instance: the record was published, the tool reported that it failed.
 * - `POST_PROCESS_FIELD_ARRAY` throws before the row is written, so the test
 *   of a failure that stops the write has a producer.
 * - `NESTED_AFTER_ALL_OPERATIONS` fails like the first, but only in a
 *   DataHandler run nested inside another one, so a test of the nested case
 *   sees the nested failure and not one of the outer run.
 *
 * Registered per test under `processDatamapClass` and `processCmdmapClass`
 * and removed again in the test's tearDown.
 */
final class FailsLikeAFlashMessageHook
{
    public const AFTER_ALL_OPERATIONS = 'afterAllOperations';

    public const AFTER_FINISH = 'afterFinish';

    public const POST_PROCESS_FIELD_ARRAY = 'postProcessFieldArray';

    public const NESTED_AFTER_ALL_OPERATIONS = 'nestedAfterAllOperations';

    public static ?string $failAt = null;

    public static function reset(): void
    {
        self::$failAt = null;
    }

    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        if (self::$failAt === self::AFTER_ALL_OPERATIONS
            || (self::$failAt === self::NESTED_AFTER_ALL_OPERATIONS && !$dataHandler->isOuterMostInstance())
        ) {
            $this->queueSessionMessage();
        }
    }

    public function processCmdmap_afterFinish(DataHandler $dataHandler): void
    {
        if (self::$failAt === self::AFTER_FINISH) {
            $this->queueSessionMessage();
        }
    }

    /**
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_postProcessFieldArray(string $status, string $table, string|int $id, array &$fieldArray, DataHandler $dataHandler): void
    {
        if (self::$failAt === self::POST_PROCESS_FIELD_ARRAY) {
            throw new RuntimeException('A test hook fails before the row is written', 1790000001);
        }
    }

    private function queueSessionMessage(): void
    {
        GeneralUtility::makeInstance(FlashMessageService::class)
            ->getMessageQueueByIdentifier()
            ->addMessage(new FlashMessage('Error during translation', '', ContextualFeedbackSeverity::ERROR, true));
    }
}
