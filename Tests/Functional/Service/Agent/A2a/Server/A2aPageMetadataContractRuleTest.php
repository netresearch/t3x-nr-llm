<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\Server;

use Closure;
use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunReference;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationDecision;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationHistory;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aPageMetadataContractRule;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aPageMetadataReadFirstRule;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aPageMetadataRows;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aTextDomainHandler;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrLlm\Service\Agent\PendingTurnDigest;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInvocationContext;
use Netresearch\NrLlm\Service\Tool\ToolInvocationPolicyInterface;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The deterministic conditions a write of the page metadata skill meets
 * before it reaches an approval (spec 055 PC1 to PC6, ADR-297), with the
 * installation and runtime of {@see A2aPageMetadataSkillFixture}.
 */
#[CoversClass(A2aPageMetadataContractRule::class)]
#[CoversClass(A2aPageMetadataRows::class)]
#[CoversClass(A2aPageMetadataReadFirstRule::class)]
final class A2aPageMetadataContractRuleTest extends AbstractFunctionalTestCase
{
    use A2aPageMetadataSkillFixture;

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'workspaces'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSkill();
    }

    protected function tearDown(): void
    {
        $this->tearDownSkill();
        parent::tearDown();
    }

    /**
     * PC3: a description over its limit is refused before an approval, the
     * model reads which rule refused it, and the governance log has the
     * refusal with the rule's identifier.
     */
    #[Test]
    public function aValueOverItsLengthIsRefusedAndLogged(): void
    {
        $run = $this->ranWith([
            $this->callOf('get_page_content', ['uid' => self::PAGE, 'language' => self::GERMAN]),
            $this->callOf('update_page_metadata', ['uid' => self::TRANSLATION, 'description' => str_repeat('a', 400)]),
            $this->answer(),
        ]);

        self::assertStringContainsString('rule: page_metadata_contract, reason: value_not_allowed', $this->toolResultOf($run));
        self::assertStringContainsString('at most 300 characters', $this->toolResultOf($run));
        self::assertSame(AgentRunStatus::COMPLETED, $this->stored($run)->statusEnum(), 'no approval was asked');
        self::assertSame(1, $this->rows('tx_nrllm_governance_event', ['decision' => 'invocation_denied', 'reason' => 'value_not_allowed']));
        self::assertStringContainsString(
            'invocationRule=page_metadata_contract',
            self::text($this->db('tx_nrllm_governance_event')->select(['detail'], 'tx_nrllm_governance_event', ['decision' => 'invocation_denied'])->fetchOne()),
        );
        self::assertSame('Old ' . self::TRANSLATION, $this->description(self::TRANSLATION));
    }

    /**
     * PC3, the other direction, end to end: the same call with a value
     * within its limit reaches the approval, is approved, writes a draft in
     * the client's workspace, and the task's `writes` artifact lists it.
     */
    #[Test]
    public function aValueWithinItsLengthIsWrittenAsADraftAndListedInTheResult(): void
    {
        $this->db('be_groups')->update('be_groups', ['tables_select' => 'pages', 'tables_modify' => 'pages', 'non_exclude_fields' => 'pages:description'], ['uid' => self::GROUP]);
        $taskId = self::text($this->send(['page' => self::PAGE, 'language' => self::GERMAN, 'fields' => ['description']])['id'] ?? '');
        $run    = $this->runOf($taskId);

        $this->responses = [
            $this->callOf('get_page_content', ['uid' => self::PAGE, 'language' => self::GERMAN]),
            $this->callOf('update_page_metadata', ['uid' => self::TRANSLATION, 'description' => 'Eine kurze Beschreibung.']),
        ];
        $runtime = $this->runtime();
        $runtime->runQueued($run->uuid);

        $waiting = $this->stored($run);
        self::assertSame(AgentRunStatus::WAITING_FOR_APPROVAL, $waiting->statusEnum());

        $this->responses = [$this->answer()];
        $state           = SuspendedRunState::fromArray(self::objectOf(json_decode($waiting->suspendedState ?? '', true)));
        $result          = $runtime->approve(AiActorContext::backendUser(1, isAdmin: true), $run->uuid, new ApprovalDecision(true, 1, (new PendingTurnDigest())->forState($state)));
        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome, (string) $result->error?->getMessage());

        $draft = $this->db('pages')->select(['description'], 'pages', ['t3ver_oid' => self::TRANSLATION, 't3ver_wsid' => self::WORKSPACE])->fetchOne();
        self::assertSame('Eine kurze Beschreibung.', $draft, "the value is a draft in the client's workspace");
        self::assertSame('Old ' . self::TRANSLATION, $this->description(self::TRANSLATION), 'live is unchanged');

        $task = $this->taskOf($this->call('GetTask', ['id' => $taskId]));
        self::assertSame('TASK_STATE_COMPLETED', self::j($task, 'status.state'));
        self::assertSame('writes', self::j($task, 'artifacts.1.name'));
        self::assertStringContainsString('pages:' . self::TRANSLATION . ' in workspace ' . self::WORKSPACE, self::text(self::j($task, 'artifacts.1.parts.0.text')));
    }

    /**
     * PC2: a field the request did not name is refused, also when the
     * request named another one.
     */
    #[Test]
    public function aFieldTheRequestDidNotNameIsRefused(): void
    {
        $run = $this->ranWith([
            $this->callOf('get_page_content', ['uid' => self::PAGE, 'language' => self::GERMAN]),
            $this->callOf('update_page_metadata', ['uid' => self::TRANSLATION, 'description' => 'Gut.', 'nav_title' => 'Anders']),
            $this->answer(),
        ]);

        self::assertStringContainsString('rule: page_metadata_contract, reason: field_not_requested', $this->toolResultOf($run));
        self::assertStringContainsString('This task may only set description.', $this->toolResultOf($run));
    }

    /**
     * PC4: a write before the page was read is refused when it runs, after
     * its approval (the rule depends on the history, ADR-293), and nothing
     * is written.
     */
    #[Test]
    public function aWriteBeforeThePageWasReadIsRefused(): void
    {
        $run = $this->ranWith([
            $this->callOf('update_page_metadata', ['uid' => self::TRANSLATION, 'description' => 'Gut.']),
        ]);
        $this->approved($run);

        self::assertStringContainsString('rule: page_metadata_read_first, reason: page_not_read', $this->toolResultOf($run));
        self::assertSame(0, $this->rows('pages', ['t3ver_oid' => self::TRANSLATION, 't3ver_wsid' => self::WORKSPACE]), 'no draft');
    }

    /**
     * PC4 is asked when the call runs, not before its card (ADR-293): a read
     * and a write in the same turn reach the approval, because the read has
     * run by the time the write does.
     */
    #[Test]
    public function aReadAndAWriteInOneTurnReachTheApproval(): void
    {
        $run = $this->ranWith([
            new CompletionResponse('', 'fake', UsageStatistics::fromTokens(1, 1), toolCalls: [
                new ToolCall('c-read', 'get_page_content', ['uid' => self::PAGE, 'language' => self::GERMAN]),
                new ToolCall('c-write', 'update_page_metadata', ['uid' => self::TRANSLATION, 'description' => 'Gut.']),
            ]),
        ]);

        self::assertSame(AgentRunStatus::WAITING_FOR_APPROVAL, $this->stored($run)->statusEnum(), $this->toolResultOf($run));
    }

    /**
     * An incomplete invocation history (a requeued run, an old suspended
     * state) refuses a write of this skill, whose read check needs the whole
     * history, and nothing else: another tool, or the same tool in a run
     * without an A2A task, is not this rule's business.
     */
    #[Test]
    public function anIncompleteHistoryRefusesOnlyAWriteOfThisSkill(): void
    {
        $run    = $this->runOf(self::text($this->send(['page' => self::PAGE, 'language' => self::GERMAN, 'fields' => ['description']])['id'] ?? ''));
        $policy = $this->getService(ToolInvocationPolicyInterface::class);
        $decide = static fn (string $tool, ?AgentRunReference $reference, ?ToolInvocationHistory $history = null): ToolInvocationDecision => $policy->decide(new ToolInvocationContext(
            $tool,
            ['uid' => self::TRANSLATION, 'description' => 'Gut.'],
            null,
            new LlmConfiguration(),
            new ToolExecutionContext(AiActorContext::anonymous(), run: $reference),
            $history ?? ToolInvocationHistory::fromStored(null),
        ));

        self::assertNotSame(A2aPageMetadataReadFirstRule::IDENTIFIER, $decide('get_page_content', new AgentRunReference($run->uid, $run->uuid))->ruleIdentifier, 'another tool');
        self::assertNotSame(A2aPageMetadataReadFirstRule::IDENTIFIER, $decide('update_page_metadata', null)->ruleIdentifier, 'a run without an A2A task');

        $write = $decide('update_page_metadata', new AgentRunReference($run->uid, $run->uuid));
        self::assertFalse($write->allowed);
        self::assertSame('history_incomplete', $write->reason);
        self::assertSame(A2aPageMetadataReadFirstRule::IDENTIFIER, $write->ruleIdentifier);

        $readSince = $decide('update_page_metadata', new AgentRunReference($run->uid, $run->uuid), ToolInvocationHistory::fromStored(null)->append('get_page_content', 'ok', null));
        self::assertNotSame(A2aPageMetadataReadFirstRule::IDENTIFIER, $readSince->ruleIdentifier, 'a read made since the history became incomplete counts');
    }

    /**
     * PC5: a row changed after the task was accepted is not written over.
     */
    #[Test]
    public function aRowChangedAfterTheTaskWasAcceptedIsRefused(): void
    {
        $taskId = self::text($this->send(['page' => self::PAGE, 'language' => self::GERMAN, 'fields' => ['description']])['id'] ?? '');
        $run    = $this->runOf($taskId);
        $this->db('pages')->update('pages', ['tstamp' => time() + 60], ['uid' => self::TRANSLATION]);

        $this->responses = [
            $this->callOf('get_page_content', ['uid' => self::PAGE, 'language' => self::GERMAN]),
            $this->callOf('update_page_metadata', ['uid' => self::TRANSLATION, 'description' => 'Gut.']),
            $this->answer(),
        ];
        $this->runtime()->runQueued($run->uuid);

        self::assertStringContainsString('rule: page_metadata_contract, reason: changed_since_accepted', $this->toolResultOf($run));
    }

    /**
     * PC1: a call aimed at another row is left to the scope, whose reason
     * the model reads; the contract rule does not hide it.
     */
    #[Test]
    public function aCallAimedAtAnotherRowIsTheScopesToRefuse(): void
    {
        $run = $this->ranWith([
            $this->callOf('get_page_content', ['uid' => self::PAGE, 'language' => self::GERMAN]),
            $this->callOf('update_page_metadata', ['uid' => self::NEIGHBOUR_TRANSLATION, 'description' => str_repeat('a', 400)]),
            $this->answer(),
        ]);

        self::assertStringContainsString('rule: assignment_scope, reason: outside_scope', $this->toolResultOf($run));
    }

    /**
     * A uid the scope admits besides the requested row, the workspace version
     * of that row or a second translation in the same language, is refused
     * before its approval: update_page_metadata takes the live uid only, so a
     * version uid could be approved and then never written.
     */
    #[Test]
    public function aUidTheScopeAlsoAdmitsIsHeldToTheContract(): void
    {
        $this->db('pages')->insert('pages', [
            'uid'       => 960, 'pid' => 1, 'title' => 'Draft of 142', 'description' => 'Old 142', 'sys_language_uid' => self::GERMAN, 'l10n_parent' => self::PAGE, 'doktype' => 1,
            't3ver_oid' => self::TRANSLATION, 't3ver_wsid' => self::WORKSPACE, 't3ver_state' => 0, 'perms_everybody' => Permission::PAGE_SHOW | Permission::PAGE_EDIT,
        ]);
        $this->db('pages')->insert('pages', ['uid' => 961, 'pid' => 1, 'title' => 'Second German row', 'sys_language_uid' => self::GERMAN, 'l10n_parent' => self::PAGE, 'doktype' => 1, 'perms_everybody' => Permission::PAGE_SHOW | Permission::PAGE_EDIT]);

        $version = $this->ranWith([
            $this->callOf('get_page_content', ['uid' => self::PAGE, 'language' => self::GERMAN]),
            $this->callOf('update_page_metadata', ['uid' => 960, 'description' => 'Gut.']),
            $this->answer(),
        ]);
        self::assertStringContainsString('rule: page_metadata_contract, reason: not_the_requested_row', $this->toolResultOf($version), 'the version uid of the requested row');
        self::assertSame(AgentRunStatus::COMPLETED, $this->stored($version)->statusEnum(), 'no approval was asked');

        $sibling = $this->ranWith([
            $this->callOf('get_page_content', ['uid' => self::PAGE, 'language' => self::GERMAN]),
            $this->callOf('update_page_metadata', ['uid' => 961, 'description' => 'Gut.']),
            $this->answer(),
        ], 'u-3');
        self::assertStringContainsString('rule: page_metadata_contract, reason: not_the_requested_row', $this->toolResultOf($sibling), 'a second translation in the language');
    }

    /**
     * A row the scope admits by a different path than the rule resolves it,
     * here a row whose l10n_parent is the translation instead of the page,
     * is held to the contract and not left to the scope unchecked: the rule
     * leaves a row to the scope only when the scope refuses it.
     */
    #[Test]
    public function aRowTheScopeAdmitsThroughATranslationIsHeldToTheContract(): void
    {
        $this->db('pages')->insert('pages', ['uid' => 980, 'pid' => 1, 'title' => 'Child of 142', 'sys_language_uid' => self::GERMAN, 'l10n_parent' => self::TRANSLATION, 'doktype' => 1, 'perms_everybody' => Permission::PAGE_SHOW | Permission::PAGE_EDIT]);

        $run = $this->ranWith([
            $this->callOf('get_page_content', ['uid' => self::PAGE, 'language' => self::GERMAN]),
            $this->callOf('update_page_metadata', ['uid' => 980, 'description' => str_repeat('a', 400)]),
            $this->answer(),
        ]);

        self::assertStringContainsString('rule: page_metadata_contract, reason: not_the_requested_row', $this->toolResultOf($run));
    }

    /**
     * A profile hidden or deleted while a segment of its run is under way
     * does not switch the contract off for the rest of that segment; the
     * resume guard refuses the next segment anyway.
     */
    #[Test]
    public function aHiddenProfileDoesNotSwitchTheContractOff(): void
    {
        $run = $this->ranWith([
            $this->callOf('get_page_content', ['uid' => self::PAGE, 'language' => self::GERMAN]),
            function (): CompletionResponse {
                $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['hidden' => 1, 'deleted' => 1], ['uid' => 1]);

                return $this->callOf('update_page_metadata', ['uid' => self::TRANSLATION, 'description' => str_repeat('a', 400)]);
            },
            $this->answer(),
        ]);

        self::assertStringContainsString('rule: page_metadata_contract', $this->toolResultOf($run));

        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['hidden' => 0, 'deleted' => 0], ['uid' => 1]);
        $gone = $this->ranWith([
            $this->callOf('get_page_content', ['uid' => self::PAGE, 'language' => self::GERMAN]),
            function (): CompletionResponse {
                $this->db('tx_nrllm_a2a_profile')->delete('tx_nrllm_a2a_profile', ['uid' => 1]);

                return $this->callOf('update_page_metadata', ['uid' => self::TRANSLATION, 'description' => 'Gut.']);
            },
            $this->answer(),
        ], 'g-2');
        self::assertStringContainsString('rule: page_metadata_contract, reason: request_unreadable', $this->toolResultOf($gone), 'a profile row that is gone');
    }

    /**
     * A value of invisible characters only is not text, and a version row of
     * the requested row in another workspace is not the requested row.
     */
    #[Test]
    public function anInvisibleValueAndAVersionOfAnotherWorkspaceAreRefused(): void
    {
        $blank = $this->ranWith([
            $this->callOf('get_page_content', ['uid' => self::PAGE, 'language' => self::GERMAN]),
            $this->callOf('update_page_metadata', ['uid' => self::TRANSLATION, 'description' => "\u{00A0}\u{200B}\u{FEFF}"]),
            $this->answer(),
        ]);
        self::assertStringContainsString('rule: page_metadata_contract, reason: value_not_allowed', $this->toolResultOf($blank));

        $this->db('pages')->insert('pages', [
            'uid'       => 970, 'pid' => 1, 'title' => 'Draft of 142 elsewhere', 'sys_language_uid' => self::GERMAN, 'l10n_parent' => self::PAGE, 'doktype' => 1,
            't3ver_oid' => self::TRANSLATION, 't3ver_wsid' => 99, 't3ver_state' => 0, 'perms_everybody' => Permission::PAGE_SHOW | Permission::PAGE_EDIT,
        ]);
        $other = $this->ranWith([
            $this->callOf('get_page_content', ['uid' => self::PAGE, 'language' => self::GERMAN]),
            $this->callOf('update_page_metadata', ['uid' => 970, 'description' => 'Gut.']),
            $this->answer(),
        ], 'o-2');
        self::assertStringContainsString('rule: page_metadata_contract, reason: not_the_requested_row', $this->toolResultOf($other));
    }

    /**
     * A history past its cap no longer starts with the request: a later
     * message of the client that names another page and field is not taken
     * for the request, and the write is refused instead of left to the scope
     * unchecked.
     */
    #[Test]
    public function aHistoryPastItsCapIsNotReadAsTheRequest(): void
    {
        $run   = $this->runOf(self::text($this->send(['page' => self::PAGE, 'language' => self::GERMAN, 'fields' => ['description']])['id'] ?? ''));
        $store = $this->getService(A2aTaskStore::class);
        $other = ['role' => 'ROLE_USER', 'parts' => [['text' => (string) json_encode(['page' => self::NEIGHBOUR, 'language' => self::GERMAN, 'fields' => ['nav_title']])]]];
        for ($i = 0; $i < A2aTaskStore::MAX_HISTORY; ++$i) {
            $store->appendHistory($run->uid, $other);
        }

        $this->responses = [
            $this->callOf('get_page_content', ['uid' => self::PAGE, 'language' => self::GERMAN]),
            $this->callOf('update_page_metadata', ['uid' => self::TRANSLATION, 'description' => str_repeat('a', 400)]),
            $this->answer(),
        ];
        $this->runtime()->runQueued($run->uuid);

        self::assertStringContainsString('rule: page_metadata_contract, reason: request_unreadable', $this->toolResultOf($run));
        self::assertSame(AgentRunStatus::COMPLETED, $this->stored($run)->statusEnum(), 'no approval was asked');
    }

    /**
     * The rule holds the page metadata skill only: a write of a task of the
     * text skill is not asked for a read first and reaches its approval.
     */
    #[Test]
    public function aTaskOfAnotherSkillIsNotHeldToTheContract(): void
    {
        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['handler' => A2aTextDomainHandler::IDENTIFIER], ['uid' => 1]);
        $this->db('tx_nrllm_configuration')->update('tx_nrllm_configuration', ['require_assignment_scope' => 0], ['uid' => 1]);
        $run = $this->runOf(self::text($this->taskOf($this->sendRaw('Improve the German description of page 42.'))['id'] ?? ''));

        $this->responses = [$this->callOf('update_page_metadata', ['uid' => self::TRANSLATION, 'nav_title' => str_repeat('a', 400)])];
        $this->runtime()->runQueued($run->uuid);

        self::assertSame(AgentRunStatus::WAITING_FOR_APPROVAL, $this->stored($run)->statusEnum());
    }

    // ------------------------------------------------------------------

    /**
     * A task for the German description of page 42, run with the given
     * model responses.
     *
     * @param list<CompletionResponse|Closure(): CompletionResponse> $responses
     */
    private function ranWith(array $responses, string $messageId = ''): AgentRun
    {
        $run             = $this->runOf(self::text($this->send(['page' => self::PAGE, 'language' => self::GERMAN, 'fields' => ['description']], $messageId)['id'] ?? ''));
        $this->responses = $responses;
        $this->runtime()->runQueued($run->uuid);

        return $run;
    }

    /**
     * Approve the waiting run as an administrator and let it finish.
     */
    private function approved(AgentRun $run): void
    {
        $waiting = $this->stored($run);
        self::assertSame(AgentRunStatus::WAITING_FOR_APPROVAL, $waiting->statusEnum());
        $this->responses = [$this->answer()];
        $state           = SuspendedRunState::fromArray(self::objectOf(json_decode($waiting->suspendedState ?? '', true)));
        $this->runtime()->approve(AiActorContext::backendUser(1, isAdmin: true), $run->uuid, new ApprovalDecision(true, 1, (new PendingTurnDigest())->forState($state)));
    }

    private function answer(): CompletionResponse
    {
        return new CompletionResponse('Done.', 'fake', UsageStatistics::fromTokens(1, 1));
    }
}
