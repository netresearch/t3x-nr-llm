<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\Server;

use Closure;
use Netresearch\NrLlm\Domain\Enum\AgentEventKind;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aResumeGuard;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aPageMetadataDomainHandler;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Agent\AgentRunExecutor;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Governance\GovernanceEventRepositoryInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\InvocationDigests;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolInvocationPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\WorkerWriteEnvironment;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The installation the page metadata tests run against (spec 055), on top of
 * {@see A2aServerFixture}: profile 1 serves the page metadata skill for
 * client 1, pinned to draft workspace 3; configuration 1 requires a draft
 * workspace and a scope; the technical user 7 edits the tree under page 1 in
 * languages 0 and 1.
 *
 *     1 site root
 *     ├ 42 the page of the request   translations 142 (language 1), 152 (2)
 *     ├ 43 its neighbour             translation 143 (language 1)
 *     ├ 44 a page without a translation
 *     ├ 45 a deleted page
 *     └ 46 a page the user may show but not edit
 *     50 a tree outside the mounts   translation 150 (language 1)
 *
 * The runtime runs over a scripted model with the container's persister, tool
 * registry, tool and invocation policies, governance log, event dispatcher,
 * worker write environment, history digests and resume guard.
 */
trait A2aPageMetadataSkillFixture
{
    use A2aServerFixture;

    private const PAGE = 42;

    private const NEIGHBOUR = 43;

    private const TRANSLATION = 142;

    private const NEIGHBOUR_TRANSLATION = 143;

    private const UNTRANSLATED = 44;

    /** A translated page in a tree outside the technical user's mounts. */
    private const OUTSIDE_MOUNT = 50;

    /** A deleted page inside the mounts. */
    private const DELETED = 45;

    private const GROUP = 70;

    /** A page the technical user may show but not edit. */
    private const SHOW_ONLY = 46;

    /** A translation of page 42 in a language the technical user may not edit. */
    private const FOREIGN_LANGUAGE_TRANSLATION = 152;

    private const GERMAN = 1;

    private const WORKSPACE = 3;

    /** @var list<CompletionResponse|Closure(): CompletionResponse> */
    private array $responses = [];

    private function setUpSkill(): void
    {
        $this->setUpServer();
        // The tool results are read back from the run's steps below, which
        // keep content only at the full privacy level.
        $this->privacyLevel('full');

        $pages = [
            [1, 0, 0, 0], [self::PAGE, 1, 0, 0], [self::NEIGHBOUR, 1, 0, 0], [self::UNTRANSLATED, 1, 0, 0],
            [self::TRANSLATION, 1, self::GERMAN, self::PAGE], [self::NEIGHBOUR_TRANSLATION, 1, self::GERMAN, self::NEIGHBOUR],
            [self::OUTSIDE_MOUNT, 0, 0, 0], [self::OUTSIDE_MOUNT + 100, 0, self::GERMAN, self::OUTSIDE_MOUNT], [self::DELETED, 1, 0, 0],
            [self::SHOW_ONLY, 1, 0, 0], [self::FOREIGN_LANGUAGE_TRANSLATION, 1, 2, self::PAGE], [self::OUTSIDE_MOUNT + 1, 0, 0, 0],
        ];
        foreach ($pages as [$uid, $pid, $language, $parent]) {
            $this->db('pages')->insert('pages', [
                'uid'             => $uid, 'pid' => $pid, 'title' => 'Page ' . $uid, 'description' => 'Old ' . $uid, 'sys_language_uid' => $language, 'l10n_parent' => $parent, 'doktype' => 1,
                'deleted'         => $uid === self::DELETED ? 1 : 0,
                'perms_everybody' => $uid === self::SHOW_ONLY ? Permission::PAGE_SHOW : Permission::PAGE_SHOW | Permission::PAGE_EDIT,
            ]);
        }

        // The technical user reads the tree under page 1 in both languages,
        // and nothing outside it.
        $this->db('be_groups')->insert('be_groups', ['uid' => self::GROUP, 'pid' => 0, 'title' => 'A2A editors', 'db_mountpoints' => '1', 'allowed_languages' => '0,1']);
        $this->db('be_users')->update('be_users', ['usergroup' => (string) self::GROUP], ['uid' => 7]);

        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['handler' => A2aPageMetadataDomainHandler::IDENTIFIER, 'may_write' => 1, 'tools' => 'get_page_content,check_page_metadata,update_page_metadata,ask_choice'], ['uid' => 1]);
        $this->db('sys_workspace')->insert('sys_workspace', ['uid' => self::WORKSPACE, 'pid' => 0, 'title' => 'Draft', 'members' => 'be_users_7']);
        $this->db('tx_nrllm_a2a_client')->update('tx_nrllm_a2a_client', ['workspace' => self::WORKSPACE], ['uid' => 1]);
        $this->db('tx_nrllm_configuration')->update('tx_nrllm_configuration', ['require_draft_workspace' => 1, 'require_assignment_scope' => 1], ['uid' => 1]);
        $this->db('tx_nrllm_provider')->update('tx_nrllm_provider', ['trust_zone' => 'local'], ['uid' => 1]);
        foreach (['update_page_metadata', 'check_page_metadata', 'get_page_content', 'ask_choice'] as $tool) {
            $this->db('tx_nrllm_tool_state')->insert('tx_nrllm_tool_state', ['tool_name' => $tool, 'enabled' => 1]);
        }
    }

    private function tearDownSkill(): void
    {
        $this->serverSettings(null);
        $this->privacyLevel(null);
        $this->getService(CacheManager::class)->getCache('ratelimiter')->flush();
    }

    /**
     * The extension configuration `privacy.level`; null removes it.
     */
    private function privacyLevel(?string $level): void
    {
        $configuration = is_array($GLOBALS['TYPO3_CONF_VARS'] ?? null) ? $GLOBALS['TYPO3_CONF_VARS'] : [];
        $extensions    = is_array($configuration['EXTENSIONS'] ?? null) ? $configuration['EXTENSIONS'] : [];
        $nrLlm         = is_array($extensions['nr_llm'] ?? null) ? $extensions['nr_llm'] : [];
        $privacy       = is_array($nrLlm['privacy'] ?? null) ? $nrLlm['privacy'] : [];
        if ($level === null) {
            unset($privacy['level']);
        } else {
            $privacy['level'] = $level;
        }

        $nrLlm['privacy']            = $privacy;
        $extensions['nr_llm']        = $nrLlm;
        $configuration['EXTENSIONS'] = $extensions;
        $GLOBALS['TYPO3_CONF_VARS']  = $configuration;
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function send(array $request, string $messageId = ''): array
    {
        return $this->taskOf($this->sendRaw(json_encode($request, JSON_THROW_ON_ERROR), $messageId));
    }

    /**
     * @return array<string, mixed>
     */
    private function sendRaw(string $text, string $messageId = ''): array
    {
        return $this->call('SendMessage', ['message' => $this->message($text, $messageId), 'configuration' => ['returnImmediately' => true]]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function callOf(string $tool, array $arguments): CompletionResponse
    {
        return new CompletionResponse('', 'fake', UsageStatistics::fromTokens(1, 1), toolCalls: [new ToolCall('c-' . $tool, $tool, $arguments)]);
    }

    /**
     * A runtime over a scripted model with the container's persister, tool
     * registry, tool policy, invocation policy, event dispatcher and resume
     * guard: the parts that enforce the scope are the production ones.
     */
    private function runtime(): AgentRuntime
    {
        $registry = $this->getService(ToolRegistry::class);
        $policy   = $this->getService(ToolCallPolicyInterface::class);
        $manager  = self::createStub(LlmServiceManagerInterface::class);
        $manager->method('chatWithToolsForConfiguration')->willReturnCallback(function (): CompletionResponse {
            $next = array_shift($this->responses);
            // A closure is a response with a side effect at the moment the
            // model answers, inside the running segment.
            $next = $next instanceof Closure ? $next() : $next;
            self::assertInstanceOf(CompletionResponse::class, $next, 'The model was asked more often than scripted.');

            return $next;
        });
        $loop = new ToolLoopService(
            $manager,
            $registry,
            $policy,
            governanceEvents: $this->getService(GovernanceEventRepositoryInterface::class),
            eventDispatcher: $this->getService(EventDispatcherInterface::class),
            invocationPolicy: $this->getService(ToolInvocationPolicyInterface::class),
            writeEnvironment: $this->getService(WorkerWriteEnvironment::class),
            invocationDigests: $this->getService(InvocationDigests::class),
        );
        $persister = $this->getService(AgentRunPersister::class);

        return new AgentRuntime(
            $loop,
            $persister,
            $this->getService(LlmConfigurationRepository::class),
            new NullLogger(),
            actingBackendUserResolver: new ActingBackendUserResolver(),
            toolEffectResolver: new ToolEffectResolver($registry),
            runExecutor: new AgentRunExecutor(
                $loop,
                $persister,
                new NullLogger(),
                new ActingBackendUserResolver(),
                new ToolEffectResolver($registry),
                resumeGuards: [$this->getService(A2aResumeGuard::class)],
                startGuards: [$this->getService(A2aResumeGuard::class)],
            ),
            toolPolicy: $policy,
        );
    }

    /**
     * The result the model read for the last call of the tool,
     * update_page_metadata unless named.
     */
    private function toolResultOf(AgentRun $run, string $tool = 'update_page_metadata'): string
    {
        $result = '';
        foreach ($this->getService(AgentRunPersister::class)->findEvents($run->uid) as $event) {
            if ($event->kind === AgentEventKind::TOOL->value && ($event->payload['toolName'] ?? null) === $tool) {
                $result = self::text($event->payload['toolResult'] ?? '');
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function queuedRequestOf(string $taskId): array
    {
        $stored  = $this->db('tx_nrllm_agentrun')->select(['queued_request'], 'tx_nrllm_agentrun', ['uid' => $this->runOf($taskId)->uid])->fetchOne();
        $decoded = json_decode($this->getService(AgentStateCodec::class)->decode(self::text($stored), AgentStateCodec::PURPOSE_QUEUED_REQUEST), true);

        return self::objectOf($decoded);
    }

    private function runOf(string $taskId): AgentRun
    {
        $uid  = $this->db(A2aTaskStore::TABLE)->select(['run_uid'], A2aTaskStore::TABLE, ['task_id' => $taskId])->fetchOne();
        $uuid = $this->db('tx_nrllm_agentrun')->select(['uuid'], 'tx_nrllm_agentrun', ['uid' => $uid])->fetchOne();
        self::assertIsString($uuid);

        return $this->find($uuid);
    }

    private function stored(AgentRun $run): AgentRun
    {
        return $this->find($run->uuid);
    }

    private function find(string $uuid): AgentRun
    {
        $found = $this->getService(AgentRunPersister::class)->findRun($uuid);
        self::assertInstanceOf(AgentRun::class, $found);

        return $found;
    }

    private function description(int $page): string
    {
        return self::text($this->db('pages')->select(['description'], 'pages', ['uid' => $page])->fetchOne());
    }
}
