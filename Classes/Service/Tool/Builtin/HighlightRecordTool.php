<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\SubjectRecord;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Name the record the current point of a process is about (ADR-214 item 9,
 * ADR-228), so the chat can show the editor where it is.
 *
 * It accepts only targets registered from the run's subject record, which the
 * runtime checked the acting user may read: the subject record itself and,
 * when the subject is a page, the content elements on it in the subject's
 * language or in all languages. Anything else is an error result. The model
 * names a table and a uid, never a selector or any markup; the UI maps the
 * target to its element. The run records it as a highlight step holding the
 * table and uid.
 *
 * Security contract (see {@see ToolInterface}): reads whether a content
 * element sits on the subject page and is not deleted; writes nothing; what
 * egresses is the table and uid the model named. Not admin-only. Ships
 * disabled: only a client that renders a highlight can use it.
 */
final readonly class HighlightRecordTool implements ToolInterface
{
    public const NAME = 'highlight_record';

    private const CONTENT_TABLE = 'tt_content';

    private const NOT_A_TARGET = 'Error: %s:%d is not a record this process may highlight. Highlight the record the process is about or, for a page, one of its content elements.';

    public function __construct(
        private ConnectionPool $connectionPool,
        private TableReadAccessService $tableAccess,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'highlight_record',
            'Show the user which record the current point of the process is about. Name the record the process is about, or one content element (tt_content) on its page. Use it when you move to the next point.',
            [
                'type'       => 'object',
                'properties' => [
                    'table' => ['type' => 'string', 'description' => 'The table of the record: pages for the page itself, tt_content for a content element.'],
                    'uid'   => ['type' => 'integer', 'description' => 'The uid of the record.'],
                ],
                'required' => ['table', 'uid'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $subject = $context->subjectRecord;
        if (!$subject instanceof SubjectRecord) {
            return ToolResult::error('Error: this run is about no record, so there is nothing to highlight.');
        }

        $table = $arguments['table'] ?? null;
        $uid   = $arguments['uid'] ?? null;
        if (!is_string($table) || !is_int($uid)) {
            return ToolResult::error('Error: highlight_record needs a table name and an integer uid.');
        }

        try {
            $target = new RecordReference($table, $uid);
        } catch (InvalidArgumentException) {
            return ToolResult::error(sprintf(self::NOT_A_TARGET, $table, $uid));
        }

        if (!$this->isTarget($target, $subject, $context)) {
            return ToolResult::error(sprintf(self::NOT_A_TARGET, $target->table, $target->uid));
        }

        return ToolResult::text(sprintf('Highlighted %s.', $target))->withHighlightTarget($target);
    }

    public function isEnabledByDefault(): bool
    {
        // Disabled until enabled deliberately: every chat is offered the
        // enabled set, and a highlight is worth something only to a client
        // that renders it.
        return false;
    }

    public function requiresAdmin(): bool
    {
        return false;
    }

    public function getGroup(): string
    {
        return 'content';
    }

    /**
     * The registered targets of a subject record (ADR-214 item 9): the record
     * itself and, for a page, a content element on it in the subject's
     * language or in all languages, which the acting user may read.
     */
    private function isTarget(RecordReference $target, SubjectRecord $subject, ToolExecutionContext $context): bool
    {
        if ($target->table === $subject->record->table && $target->uid === $subject->record->uid) {
            return true;
        }

        if ($subject->record->table !== 'pages' || $target->table !== self::CONTENT_TABLE) {
            return false;
        }

        if (!$this->tableAccess->canReadTable($context->actingBackendUser(), self::CONTENT_TABLE)) {
            return false;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::CONTENT_TABLE);
        // Not deleted, and live: the acting user a run resolves works in no
        // workspace.
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, 0));

        $count = $queryBuilder
            ->count('uid')
            ->from(self::CONTENT_TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($target->uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($subject->record->uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('sys_language_uid', $queryBuilder->createNamedParameter([$subject->language, -1], Connection::PARAM_INT_ARRAY)),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) && (int) $count > 0;
    }
}
