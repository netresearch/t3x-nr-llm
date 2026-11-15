<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server\Handler;

use Netresearch\NrLlm\Domain\ValueObject\AgentRunReference;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationDecision;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSkillProfileRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\PreApprovalInvocationRuleInterface;
use Netresearch\NrLlm\Service\Tool\Scope\DatabaseRecordTopology;
use Netresearch\NrLlm\Service\Tool\Scope\RecordFacts;
use Netresearch\NrLlm\Service\Tool\ToolInvocationContext;
use Netresearch\NrLlm\Service\Tool\ToolInvocationRuleInterface;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The deterministic conditions a write of the page metadata skill must meet
 * before it reaches an approval (ADR-297, spec 055 PC1 to PC6). It holds a
 * call of `update_page_metadata` in a run whose A2A task belongs to a profile
 * with the handler `nr_llm.page_metadata.v1` to the request the client sent:
 *
 * - the row the request names (the page, or its translation in the
 *   requested language) still exists, and the call names it, directly or
 *   through its workspace version; any other row of that page in that
 *   language is refused, and a row of another page or language is left to
 *   the scope of the run (ADR-295), which refuses it with its own reason;
 * - it sets only fields the request names, each as text within its length;
 * - the run read the page with `get_page_content` before;
 * - the row has not changed since the task was accepted.
 *
 * The request is read from the task's first message, the one the handler
 * checked, with the same parser. A run of any other origin, and any other
 * tool, is not this rule's business. The values are fixed here, not in the
 * prompt and not in a configuration.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aPageMetadataContractRule implements ToolInvocationRuleInterface, PreApprovalInvocationRuleInterface
{
    public const IDENTIFIER = 'page_metadata_contract';

    public const REASON_UNREADABLE = 'request_unreadable';

    public const REASON_ROW = 'not_the_requested_row';

    public const REASON_FIELD = 'field_not_requested';

    public const REASON_VALUE = 'value_not_allowed';

    public const REASON_NOT_READ = 'page_not_read';

    public const REASON_CHANGED = 'changed_since_accepted';

    /**
     * The longest value per field, in characters. Titles follow what search
     * results and social cards show; the rest follow the columns.
     *
     * @var array<string, int>
     */
    public const MAX_LENGTH = [
        'title'               => 255,
        'subtitle'            => 255,
        'nav_title'           => 255,
        'abstract'            => 1000,
        'description'         => 300,
        'keywords'            => 255,
        'seo_title'           => 100,
        'og_title'            => 100,
        'og_description'      => 300,
        'twitter_title'       => 100,
        'twitter_description' => 300,
        'twitter_card'        => 255,
    ];

    public function __construct(
        private ConnectionPool $connectionPool,
        private AgentStateCodec $codec,
        private A2aPageMetadataRows $rows,
    ) {}

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function requiresCompleteHistory(): bool
    {
        return true;
    }

    public function decide(ToolInvocationContext $context): ToolInvocationDecision
    {
        $run = $context->execution->run;
        if ($context->toolName !== A2aPageMetadataDomainHandler::WRITE_TOOL || !$run instanceof AgentRunReference) {
            return ToolInvocationDecision::allow();
        }

        $task = $this->task($run->uid);
        if ($task === null) {
            return ToolInvocationDecision::allow();
        }

        $handler = $this->handlerOf(A2aPageMetadataRows::int($task['profile_uid'] ?? 0));
        if ($handler === null) {
            return ToolInvocationDecision::deny(self::REASON_UNREADABLE, 'The skill of this task can no longer be read, so no write is allowed. Nothing was changed.');
        }

        if ($handler !== A2aPageMetadataDomainHandler::IDENTIFIER) {
            return ToolInvocationDecision::allow();
        }

        $request = $this->request($task);
        if (!$request instanceof A2aPageMetadataRequest) {
            return ToolInvocationDecision::deny(self::REASON_UNREADABLE, 'The request of this task cannot be read, so no write is allowed. Nothing was changed.');
        }

        return $this->decideFor($request, $context, A2aPageMetadataRows::int($task['crdate'] ?? 0));
    }

    private function decideFor(A2aPageMetadataRequest $request, ToolInvocationContext $context, int $acceptedAt): ToolInvocationDecision
    {
        $workspace = $context->execution->actor->workspaceId ?? 0;
        $target    = $this->rows->target($request, $workspace);
        $uid       = $context->arguments['uid'] ?? null;
        $uid       = is_int($uid) ? $uid : (is_string($uid) ? filter_var($uid, FILTER_VALIDATE_INT) : false);
        if ($target === null) {
            return ToolInvocationDecision::deny(self::REASON_ROW, sprintf(
                'The %s of page %d no longer exists in this workspace. Nothing was changed.',
                $request->language === 0 ? 'default-language row' : sprintf('translation in language %d', $request->language),
                $request->page,
            ));
        }

        if ($uid === false || $uid !== $target && !$this->isTarget($uid, $target, $request, $workspace)) {
            $facts = $uid === false ? null : (new DatabaseRecordTopology($this->connectionPool))->row('pages', $uid, $workspace);
            if ($facts instanceof RecordFacts && (($facts->translationOf ?? $facts->liveUid) !== $request->page || $facts->language !== $request->language)) {
                // Another page or language is the scope's to refuse
                // (ADR-295), with its own reason.
                return ToolInvocationDecision::allow();
            }

            return ToolInvocationDecision::deny(self::REASON_ROW, sprintf('This task may only write pages:%d. Nothing was changed.', $target));
        }

        $fields = array_values(array_filter(array_keys($context->arguments), static fn (int|string $key): bool => $key !== 'uid'));
        foreach ($fields as $field) {
            if (!in_array($field, $request->fields, true)) {
                return ToolInvocationDecision::deny(self::REASON_FIELD, sprintf('This task may only set %s. Nothing was changed.', implode(', ', $request->fields)));
            }

            $value = $context->arguments[$field];
            $limit = self::MAX_LENGTH[$field] ?? 0;
            if (!is_string($value) || preg_match('/[^\s\p{Z}\p{Cf}]/u', $value) !== 1 || mb_strlen($value) > $limit) {
                return ToolInvocationDecision::deny(self::REASON_VALUE, sprintf('The value of %s must be non-empty text of at most %d characters. Nothing was changed.', $field, $limit));
            }
        }

        if ($fields === []) {
            return ToolInvocationDecision::deny(self::REASON_FIELD, sprintf('This task sets %s; the call names none of them. Nothing was changed.', implode(', ', $request->fields)));
        }

        if (!$this->hasRead($context)) {
            return ToolInvocationDecision::deny(self::REASON_NOT_READ, 'Read the page with get_page_content before writing its metadata. Nothing was changed.');
        }

        $row = $this->rows->page($target, $workspace);
        if ($row === null || A2aPageMetadataRows::int($row['tstamp'] ?? 0) > $acceptedAt) {
            return ToolInvocationDecision::deny(self::REASON_CHANGED, 'The record changed after this task was accepted. Nothing was changed; the client has to send the task again.');
        }

        return ToolInvocationDecision::allow();
    }

    /**
     * Whether the uid names the target row as the scope resolves it: the row
     * itself or its version in the workspace.
     */
    private function isTarget(int $uid, int $target, A2aPageMetadataRequest $request, int $workspace): bool
    {
        $topology = new DatabaseRecordTopology($this->connectionPool);
        $facts    = $topology->row('pages', $uid, $workspace);

        // A version row belongs to one workspace; only the client's counts.
        return $facts instanceof RecordFacts && $facts->liveUid === $target && $facts->language === $request->language
            && in_array((int) ($topology->storedValue('pages', $uid, 't3ver_wsid') ?? -1), [0, $workspace], true);
    }

    private function hasRead(ToolInvocationContext $context): bool
    {
        foreach ($context->history->entries as $entry) {
            if (($entry['tool'] ?? null) === A2aPageMetadataDomainHandler::READ_TOOL && ($entry['outcome'] ?? null) === 'ok') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function task(int $runUid): ?array
    {
        if ($runUid < 1) {
            return null;
        }

        $query = $this->connectionPool->getQueryBuilderForTable(A2aTaskStore::TABLE);
        $query->getRestrictions()->removeAll();
        $row = $query->select('profile_uid', 'history', 'crdate')->from(A2aTaskStore::TABLE)
            ->where($query->expr()->eq('run_uid', $query->createNamedParameter($runUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * The handler of the task's profile, read whether or not the profile was
     * hidden or deleted since; null when there is no such profile row.
     */
    private function handlerOf(int $profileUid): ?string
    {
        if ($profileUid < 1) {
            return null;
        }

        $query = $this->connectionPool->getQueryBuilderForTable(A2aSkillProfileRepository::TABLE);
        $query->getRestrictions()->removeAll();
        $handler = $query->select('handler')->from(A2aSkillProfileRepository::TABLE)
            ->where($query->expr()->eq('uid', $query->createNamedParameter($profileUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        return is_string($handler) ? $handler : null;
    }

    /**
     * The request of the task's first message, read as the handler read it.
     *
     * @param array<string, mixed> $task
     */
    private function request(array $task): ?A2aPageMetadataRequest
    {
        $stored = is_string($task['history'] ?? null) ? $task['history'] : '';
        if ($stored === '') {
            return null;
        }

        try {
            $history = json_decode($this->codec->decode($stored, AgentStateCodec::PURPOSE_A2A_TASK_CONTENT), true, 64, JSON_THROW_ON_ERROR);

            // A full history may have dropped the first message, and a later
            // message of the client is not the request.
            if (!is_array($history) || count($history) >= A2aTaskStore::MAX_HISTORY) {
                return null;
            }

            $parts = is_array($history[0] ?? null) && is_array($history[0]['parts'] ?? null) ? $history[0]['parts'] : [];

            // The text parts joined as A2aMessage::text() joins them; the
            // server accepts no other part kind.
            $texts = [];
            foreach ($parts as $part) {
                if (is_array($part) && is_string($part['text'] ?? null)) {
                    $texts[] = $part['text'];
                }
            }

            return $texts === [] ? null : A2aPageMetadataRequest::fromText(implode("\n\n", $texts));
        } catch (Throwable) {
            return null;
        }
    }
}
