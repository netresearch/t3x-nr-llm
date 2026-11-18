<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrLlm\Service\Agent\PendingTurnDigest;

/**
 * The approved description write of the page metadata skill to page 42 of
 * {@see A2aPageMetadataSkillFixture}, and what a test reads after it.
 */
trait A2aPageMetadataApprovedWrite
{
    /**
     * Sends a request for the default-language page, runs it to the approval
     * of `update_page_metadata` and approves it.
     *
     * @param class-string|null $hook   a DataHandler hook registered for the run
     * @param string            $fields the technical user's `non_exclude_fields`
     *
     * @return array{0: AgentRun, 1: string}
     */
    private function approvedWrite(?string $hook = null, string $fields = 'pages:description'): array
    {
        if ($hook !== null) {
            $this->registerHook('processDatamapClass', $hook);
        }

        $this->db('be_groups')->update('be_groups', ['tables_select' => 'pages', 'tables_modify' => 'pages', 'non_exclude_fields' => $fields], ['uid' => self::GROUP]);
        $taskId = self::text($this->send(['page' => self::PAGE, 'language' => 0, 'fields' => ['description']])['id'] ?? '');
        $run    = $this->runOf($taskId);

        $this->responses = [
            $this->callOf('get_page_content', ['uid' => self::PAGE, 'language' => 0]),
            $this->callOf('check_page_metadata', ['uid' => self::PAGE, 'description' => 'Eine kurze Beschreibung.']),
            $this->callOf('update_page_metadata', ['uid' => self::PAGE, 'description' => 'Eine kurze Beschreibung.']),
        ];
        $runtime = $this->runtime();
        $runtime->runQueued($run->uuid);

        $waiting = $this->stored($run);
        self::assertSame(AgentRunStatus::WAITING_FOR_APPROVAL, $waiting->statusEnum());

        $this->responses = [new CompletionResponse('Done.', 'fake', UsageStatistics::fromTokens(1, 1))];
        $state           = SuspendedRunState::fromArray(self::objectOf(json_decode($waiting->suspendedState ?? '', true)));
        $result          = $runtime->approve(AiActorContext::backendUser(1, isAdmin: true), $run->uuid, new ApprovalDecision(true, 1, (new PendingTurnDigest())->forState($state)));
        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome, (string) $result->error?->getMessage());

        return [$run, $taskId];
    }

    private function draftOf(int $page): mixed
    {
        return $this->db('pages')->select(['description'], 'pages', ['t3ver_oid' => $page, 't3ver_wsid' => self::WORKSPACE])->fetchOne();
    }

    /**
     * The live uid of every row the client's workspace holds.
     *
     * @return list<int>
     */
    private function versionedPages(): array
    {
        $uids = $this->db('pages')
            ->executeQuery('SELECT t3ver_oid, uid FROM pages WHERE t3ver_wsid = ? ORDER BY uid', [self::WORKSPACE])
            ->fetchAllAssociative();

        return array_map(static fn (array $row): int => (int) ($row['t3ver_oid'] > 0 ? $row['t3ver_oid'] : $row['uid']), $uids);
    }

    /**
     * The `writes` artifact of the completed task, as the A2A client reads it.
     */
    private function writesOf(string $taskId): string
    {
        $task = $this->taskOf($this->call('GetTask', ['id' => $taskId]));
        self::assertSame('TASK_STATE_COMPLETED', self::j($task, 'status.state'));
        self::assertSame('writes', self::j($task, 'artifacts.1.name'));

        return self::text(self::j($task, 'artifacts.1.parts.0.text'));
    }
}
