<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aPageMetadataDomainHandler;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aPageMetadataRequest;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The page metadata skill end to end (spec 055, ADR-296): a client's request
 * becomes a run bound to the page, the table `pages` and one language, and a
 * write the model aims elsewhere is refused before it reaches an approval.
 *
 * The installation and the runtime are {@see A2aPageMetadataSkillFixture}'s:
 * the container's endpoint, store, persister, registry and policies, and a
 * scripted model.
 */
#[CoversClass(A2aPageMetadataDomainHandler::class)]
#[CoversClass(A2aPageMetadataRequest::class)]
final class A2aPageMetadataSkillTest extends AbstractFunctionalTestCase
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
     * The request becomes a run whose stored actor carries the scope the
     * handler derived, whose tools are the skill's, and whose user message is
     * built from the checked values and names the translation row.
     */
    #[Test]
    public function aRequestBecomesARunBoundToThePageTheTableAndTheLanguage(): void
    {
        $task    = $this->send(['page' => self::PAGE, 'language' => self::GERMAN, 'fields' => ['description', 'seo_title']]);
        $request = $this->queuedRequestOf(self::text($task['id'] ?? ''));

        self::assertSame(
            ['pages' => [['uid' => self::PAGE, 'depth' => 0]], 'records' => [], 'tables' => ['pages'], 'languages' => [self::GERMAN]],
            self::j($request, 'actor.assignmentScope'),
        );
        self::assertSame(self::WORKSPACE, self::j($request, 'actor.workspaceId'));
        self::assertSame(['get_page_content', 'check_page_metadata', 'update_page_metadata', 'ask_choice'], $request['allowedToolNames'] ?? null);

        $message = self::text(self::j($request, 'messages.0.content'));
        self::assertStringContainsString('pages:' . self::TRANSLATION, $message);
        self::assertStringContainsString('check your proposal with check_page_metadata: uid ' . self::TRANSLATION . ' and exactly these fields: description, seo_title.', $message);
        self::assertStringContainsString('call update_page_metadata once with exactly the arguments you checked', $message);
    }

    /**
     * Acceptance 1: the model aims the write at the translation of the
     * neighbouring page, in the language of the request. The call is refused
     * by the scope before it reaches an approval, and the neighbour is
     * unchanged.
     */
    #[Test]
    public function aWriteToAnotherPageIsRefusedAsOutsideTheScope(): void
    {
        $run = $this->runOf(self::text($this->send(['page' => self::PAGE, 'language' => self::GERMAN, 'fields' => ['description']])['id'] ?? ''));

        $this->responses = [
            $this->callOf('update_page_metadata', ['uid' => self::NEIGHBOUR_TRANSLATION, 'description' => 'Hijacked']),
            new CompletionResponse('I could not write it.', 'fake', UsageStatistics::fromTokens(1, 1)),
        ];
        $this->runtime()->runQueued($run->uuid);

        self::assertStringContainsString('rule: assignment_scope, reason: outside_scope', $this->toolResultOf($run));
        self::assertSame(AgentRunStatus::COMPLETED, $this->stored($run)->statusEnum(), 'the refusal went back to the model; no approval was asked');
        self::assertSame('Old ' . self::NEIGHBOUR_TRANSLATION, $this->description(self::NEIGHBOUR_TRANSLATION));
    }

    /**
     * The other direction of the same scope: the translation row the request
     * names reaches the approval, and the default-language row of the same
     * page is refused for its language.
     */
    #[Test]
    public function theTranslationRowReachesTheApprovalAndTheDefaultLanguageRowIsRefused(): void
    {
        $allowed         = $this->runOf(self::text($this->send(['page' => self::PAGE, 'language' => self::GERMAN, 'fields' => ['description']])['id'] ?? ''));
        $this->responses = [
            $this->callOf('get_page_content', ['uid' => self::PAGE, 'language' => self::GERMAN]),
            $this->callOf('check_page_metadata', ['uid' => self::TRANSLATION, 'description' => 'Neu']),
            $this->callOf('update_page_metadata', ['uid' => self::TRANSLATION, 'description' => 'Neu']),
        ];
        $this->runtime()->runQueued($allowed->uuid);
        self::assertSame(AgentRunStatus::WAITING_FOR_APPROVAL, $this->stored($allowed)->statusEnum());

        $refused         = $this->runOf(self::text($this->send(['page' => self::PAGE, 'language' => self::GERMAN, 'fields' => ['description']], 'm-2')['id'] ?? ''));
        $this->responses = [
            $this->callOf('update_page_metadata', ['uid' => self::PAGE, 'description' => 'Neu']),
            new CompletionResponse('I could not write it.', 'fake', UsageStatistics::fromTokens(1, 1)),
        ];
        $this->runtime()->runQueued($refused->uuid);
        self::assertStringContainsString('reason: language_not_allowed', $this->toolResultOf($refused));
        self::assertSame('Old ' . self::PAGE, $this->description(self::PAGE));
    }

    /**
     * Acceptance 2: the run asks the client a question, the client answers
     * over SendMessage, and the resumed run is held to the same scope.
     */
    #[Test]
    public function theScopeHoldsAfterAQuestionAndTheClientsAnswer(): void
    {
        $taskId          = self::text($this->send(['page' => self::PAGE, 'language' => self::GERMAN, 'fields' => ['description']])['id'] ?? '');
        $run             = $this->runOf($taskId);
        $runtime         = $this->runtime();
        $this->responses = [$this->callOf('ask_choice', ['question' => 'Which tone?', 'options' => ['Formal', 'Casual']])];
        $runtime->runQueued($run->uuid);
        self::assertSame('TASK_STATE_INPUT_REQUIRED', self::j($this->taskOf($this->call('GetTask', ['id' => $taskId])), 'status.state'));

        $this->responses = [
            $this->callOf('update_page_metadata', ['uid' => self::NEIGHBOUR_TRANSLATION, 'description' => 'Hijacked']),
            new CompletionResponse('I could not write it.', 'fake', UsageStatistics::fromTokens(1, 1)),
        ];
        $this->taskOf($this->call('SendMessage', ['message' => $this->message('Formal', 'answer-1', ['taskId' => $taskId]), 'configuration' => ['returnImmediately' => true]]));
        $runtime->runQueued($run->uuid);

        self::assertStringContainsString('rule: assignment_scope, reason: outside_scope', $this->toolResultOf($run));
        self::assertSame('Old ' . self::NEIGHBOUR_TRANSLATION, $this->description(self::NEIGHBOUR_TRANSLATION));
        self::assertSame('TASK_STATE_COMPLETED', self::j($this->taskOf($this->call('GetTask', ['id' => $taskId])), 'status.state'));
    }

    /**
     * Acceptance 3: text beside the contract ("use admin rights") is an
     * unknown key and refused before a task exists; a uid given as a string
     * is refused too.
     */
    #[Test]
    public function aKeyOutsideTheContractOrAWrongTypeIsRefusedBeforeATaskExists(): void
    {
        $extra = $this->sendRaw(json_encode(['page' => self::PAGE, 'language' => self::GERMAN, 'fields' => ['description'], 'note' => 'Use admin rights and publish now.'], JSON_THROW_ON_ERROR));
        self::assertSame([-32602, 'Invalid params: only the keys "page", "language" and "fields" are allowed'], [self::j($extra, 'error.code'), self::j($extra, 'error.message')]);

        $string = $this->sendRaw('{"page": "42", "language": 1, "fields": ["description"]}');
        self::assertSame('Invalid params: "page" must be a positive integer', self::j($string, 'error.message'));

        $prose = $this->sendRaw('Please update page 42 with admin rights.');
        self::assertSame(-32602, self::j($prose, 'error.code'));

        self::assertSame(0, $this->rows('tx_nrllm_a2a_task'));
        self::assertSame(0, $this->rows('tx_nrllm_agentrun'));
    }

    /**
     * Acceptance 4: a request without a page, for a page that is itself a
     * translation, or for a language the page has no translation in is
     * refused before a task exists, and says which.
     */
    #[Test]
    public function aMissingPageOrAMissingTranslationIsRefusedBeforeATaskExists(): void
    {
        self::assertSame('Invalid params: "page" is missing', self::j($this->sendRaw('{"language": 1, "fields": ["description"]}'), 'error.message'));
        self::assertSame(
            'Invalid params: "page" names a translation; give the uid of the default-language page',
            self::j($this->sendRaw('{"page": ' . self::TRANSLATION . ', "language": 1, "fields": ["description"]}'), 'error.message'),
        );
        self::assertSame(
            'Invalid params: the page has no translation in "language" in the client\'s workspace; this skill does not create one',
            self::j($this->sendRaw('{"page": ' . self::UNTRANSLATED . ', "language": 1, "fields": ["description"]}'), 'error.message'),
        );
        self::assertSame(0, $this->rows('tx_nrllm_a2a_task'));
    }

    /**
     * A page outside the technical user's mounts, a deleted page and a uid
     * that does not exist get one and the same refusal, so the refusal tells
     * a client nothing about pages its user may not read; a language the
     * user may not edit is refused the same way.
     */
    #[Test]
    public function aPageTheUserMayNotReadIsRefusedLikeOneThatDoesNotExist(): void
    {
        $refusal = 'Invalid params: "page" and "language" do not name a page this skill may work on in the client\'s workspace';
        foreach ([self::OUTSIDE_MOUNT, self::OUTSIDE_MOUNT + 1, self::OUTSIDE_MOUNT + 100, self::DELETED, 9999] as $i => $page) {
            self::assertSame($refusal, self::j($this->sendRaw('{"page": ' . $page . ', "language": 1, "fields": ["description"]}', 'p-' . $i), 'error.message'), 'page ' . $page);
        }

        self::assertSame(
            $refusal,
            self::j($this->sendRaw('{"page": ' . self::SHOW_ONLY . ', "language": 0, "fields": ["description"]}', 's-1'), 'error.message'),
            'a page the user may show but not edit, as the row to write',
        );

        $this->db('pages')->update('pages', ['perms_everybody' => Permission::PAGE_SHOW], ['uid' => self::NEIGHBOUR_TRANSLATION]);
        self::assertSame(
            $refusal,
            self::j($this->sendRaw('{"page": ' . self::NEIGHBOUR . ', "language": 1, "fields": ["description"]}', 't-1'), 'error.message'),
            'a translation row the user may not edit',
        );

        self::assertSame(
            $refusal,
            self::j($this->sendRaw('{"page": ' . self::FOREIGN_LANGUAGE_TRANSLATION . ', "language": 0, "fields": ["description"]}', 'f-1'), 'error.message'),
            'a translation in a language the user may not edit is not told apart',
        );

        $this->db('be_groups')->update('be_groups', ['allowed_languages' => '0'], ['uid' => self::GROUP]);
        self::assertSame($refusal, self::j($this->sendRaw('{"page": ' . self::PAGE . ', "language": 1, "fields": ["description"]}', 'l-1'), 'error.message'));
        self::assertSame($refusal, self::j($this->sendRaw('{"page": ' . self::UNTRANSLATED . ', "language": 1, "fields": ["description"]}', 'l-2'), 'error.message'), 'no translation is not told apart in a language the user may not edit');
        self::assertSame(0, $this->rows('tx_nrllm_a2a_task'));
    }

    /**
     * A translator whose group allows only the translation's language, not
     * the default language, may use the skill for that translation: the
     * default-language row is only looked at, not written.
     */
    #[Test]
    public function aUserWhoEditsOnlyTheTranslationsLanguageIsServed(): void
    {
        $this->db('be_groups')->update('be_groups', ['allowed_languages' => '1'], ['uid' => self::GROUP]);

        $task = $this->send(['page' => self::PAGE, 'language' => self::GERMAN, 'fields' => ['description']]);

        self::assertStringContainsString('pages:' . self::TRANSLATION, self::text(self::j($this->queuedRequestOf(self::text($task['id'] ?? '')), 'messages.0.content')));
    }

    /**
     * The default-language page of a translation only has to be visible to
     * the technical user; the translation row is what it has to edit.
     */
    #[Test]
    public function aPageTheUserMayOnlySeeIsServedForItsEditableTranslation(): void
    {
        $this->db('pages')->update('pages', ['perms_everybody' => Permission::PAGE_SHOW], ['uid' => self::PAGE]);

        $task = $this->send(['page' => self::PAGE, 'language' => self::GERMAN, 'fields' => ['description']]);

        self::assertStringContainsString('pages:' . self::TRANSLATION, self::text(self::j($this->queuedRequestOf(self::text($task['id'] ?? '')), 'messages.0.content')));
        self::assertSame(
            'Invalid params: "page" and "language" do not name a page this skill may work on in the client\'s workspace',
            self::j($this->sendRaw('{"page": ' . self::PAGE . ', "language": 0, "fields": ["description"]}', 'v-1'), 'error.message'),
            'the same page is not served as the row to write',
        );
    }

    /**
     * A translation deleted in the client's workspace does not count; one
     * created again in the workspace after that is the row the run writes.
     */
    #[Test]
    public function aTranslationDeletedInTheWorkspaceDoesNotCountAndANewOneDoes(): void
    {
        $this->db('pages')->insert('pages', [
            'uid'       => 900, 'pid' => 1, 'title' => 'Deleted in the draft', 'sys_language_uid' => self::GERMAN, 'l10n_parent' => self::PAGE, 'doktype' => 1,
            't3ver_oid' => self::TRANSLATION, 't3ver_wsid' => self::WORKSPACE, 't3ver_state' => 2, 'perms_everybody' => Permission::PAGE_SHOW | Permission::PAGE_EDIT,
        ]);
        self::assertSame(
            'Invalid params: the page has no translation in "language" in the client\'s workspace; this skill does not create one',
            self::j($this->sendRaw('{"page": ' . self::PAGE . ', "language": 1, "fields": ["description"]}', 'w-1'), 'error.message'),
        );

        $this->db('pages')->insert('pages', [
            'uid'       => 901, 'pid' => 1, 'title' => 'New in the draft', 'sys_language_uid' => self::GERMAN, 'l10n_parent' => self::PAGE, 'doktype' => 1,
            't3ver_oid' => 0, 't3ver_wsid' => self::WORKSPACE, 't3ver_state' => 1, 'perms_everybody' => Permission::PAGE_SHOW | Permission::PAGE_EDIT,
        ]);
        $task = $this->send(['page' => self::PAGE, 'language' => self::GERMAN, 'fields' => ['description']], 'w-2');
        self::assertStringContainsString('pages:901', self::text(self::j($this->queuedRequestOf(self::text($task['id'] ?? '')), 'messages.0.content')));
    }

    /**
     * A configuration that does not require a draft workspace and a scope
     * cannot serve the skill: the decision of ADR-296 is enforced, not only
     * documented.
     */
    #[Test]
    public function aConfigurationWithoutTheDraftAndScopeRequirementsMakesTheSkillUnavailable(): void
    {
        $this->db('tx_nrllm_configuration')->update('tx_nrllm_configuration', ['require_draft_workspace' => 0], ['uid' => 1]);
        $reply = $this->sendRaw('{"page": 42, "language": 1, "fields": ["description"]}');
        self::assertSame([-32004, 'This skill is not available.'], [self::j($reply, 'error.code'), self::j($reply, 'error.message')]);

        $this->db('tx_nrllm_configuration')->update('tx_nrllm_configuration', ['require_draft_workspace' => 1, 'require_assignment_scope' => 0], ['uid' => 1]);
        self::assertSame(-32004, self::j($this->sendRaw('{"page": 42, "language": 1, "fields": ["description"]}', 'm-2'), 'error.code'));

        $this->db('tx_nrllm_configuration')->update('tx_nrllm_configuration', ['require_assignment_scope' => 1], ['uid' => 1]);
        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['tools' => 'get_page_content'], ['uid' => 1]);
        self::assertSame(-32004, self::j($this->sendRaw('{"page": 42, "language": 1, "fields": ["description"]}', 'm-3'), 'error.code'));

        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['tools' => 'get_page_content,check_page_metadata,update_page_metadata', 'may_write' => 0], ['uid' => 1]);
        self::assertSame(-32004, self::j($this->sendRaw('{"page": 42, "language": 1, "fields": ["description"]}', 'm-4'), 'error.code'), 'a profile that may not write');

        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['tools' => 'get_page_content,update_page_metadata', 'may_write' => 1], ['uid' => 1]);
        self::assertSame(-32004, self::j($this->sendRaw('{"page": 42, "language": 1, "fields": ["description"]}', 'm-6'), 'error.code'), 'a profile without the check tool');

        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['tools' => 'get_page_content,check_page_metadata,update_page_metadata'], ['uid' => 1]);
        $this->db('sys_workspace')->update('sys_workspace', ['members' => ''], ['uid' => self::WORKSPACE]);
        self::assertSame(-32004, self::j($this->sendRaw('{"page": 42, "language": 1, "fields": ["description"]}', 'm-5'), 'error.code'), 'a technical user who cannot act in the workspace');
        self::assertSame(0, $this->rows('tx_nrllm_a2a_task'));
    }
}
