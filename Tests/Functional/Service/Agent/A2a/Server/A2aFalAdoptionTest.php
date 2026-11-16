<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Domain\Enum\AgentEventKind;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Service\Agent\A2a\File\A2aFileInputPreparer;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aResumeGuard;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Agent\AgentRunExecutor;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Agent\RunScopedStateInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * FAL and the A2A server role (spec 051 A2A-FILE6, A2A-FILE7, ADR-284).
 *
 * The technical user 7 reads storage 1 through the file mounts of group 70:
 * mount 1 is `/docs/`, mount 2 is `/other/`. File 101 lies in `/docs/`, file
 * 102 in `/other/`. The group starts with mount 1 only.
 *
 * Every run here runs in this one PHP process with the container's tool
 * registry, and so with the container's {@see FalStorageGate}: the process is
 * the long-running worker of A2A-FILE7.
 */
#[CoversClass(FalStorageGate::class)]
#[CoversClass(AgentRuntime::class)]
final class A2aFalAdoptionTest extends AbstractFunctionalTestCase
{
    use A2aServerFixture;

    private const GROUP = 70;

    private const IN_DOCS = 101;

    private const IN_OTHER = 102;

    private const NOT_PERMITTED = 'Asset not found or not permitted.';

    private const STORAGE_CONFIGURATION = '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>
<T3FlexForms>
    <data>
        <sheet index="sDEF">
            <language index="lDEF">
                <field index="basePath"><value index="vDEF">fileadmin/</value></field>
                <field index="pathType"><value index="vDEF">relative</value></field>
                <field index="caseSensitive"><value index="vDEF">1</value></field>
            </language>
        </sheet>
    </data>
</T3FlexForms>';

    /** @var list<CompletionResponse> */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServer();
        // The tool results are read back from the run's events, which keep
        // content only at the full privacy level.
        $this->privacyLevel('full');

        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/docs');
        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/other');
        $this->db('sys_file_storage')->insert('sys_file_storage', [
            'uid'           => 1, 'pid' => 0, 'name' => 'Main storage', 'driver' => 'Local',
            'configuration' => self::STORAGE_CONFIGURATION,
            'is_online'     => 1, 'is_browsable' => 1, 'is_public' => 1, 'is_writable' => 1,
        ]);
        $this->db('sys_filemounts')->insert('sys_filemounts', ['uid' => 1, 'pid' => 0, 'title' => 'Docs', 'identifier' => '1:/docs/', 'read_only' => 1]);
        $this->db('sys_filemounts')->insert('sys_filemounts', ['uid' => 2, 'pid' => 0, 'title' => 'Other', 'identifier' => '1:/other/', 'read_only' => 1]);
        $this->db('be_groups')->insert('be_groups', [
            'uid'              => self::GROUP, 'pid' => 0, 'title' => 'A2A readers', 'file_mountpoints' => '1',
            'file_permissions' => 'readFolder,readFile',
        ]);
        // options=3: the user takes the db and file mounts of its groups.
        $this->db('be_users')->update('be_users', ['usergroup' => (string) self::GROUP, 'options' => 3], ['uid' => 7]);

        $this->indexFile(self::IN_DOCS, '/docs/report.txt', 'The report.');
        $this->indexFile(self::IN_OTHER, '/other/notes.txt', 'The notes.');
    }

    protected function tearDown(): void
    {
        $this->serverSettings(null);
        $this->privacyLevel(null);
        $this->getService(CacheManager::class)->getCache('ratelimiter')->flush();
        parent::tearDown();
    }

    /**
     * A2A-FILE7: the file mounts of the technical user change between two
     * runs in one worker process; the second run sees the change. The first
     * run's checks fill the gate's cache with the user's storage for both
     * files, so a cache that outlived the run would answer the second run with
     * the old mounts.
     */
    #[Test]
    public function aChangedFileMountTakesEffectOnTheNextRunInTheSameProcess(): void
    {
        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['tools' => 'describe_fal_asset'], ['uid' => 1]);

        $first = $this->runDescribing([self::IN_DOCS, self::IN_OTHER]);
        self::assertStringStartsWith('File [' . self::IN_DOCS . ']', $first[0], 'run 1 reads the file inside its mount');
        self::assertSame(self::NOT_PERMITTED, $first[1], 'run 1 is refused the file outside its mount');

        $this->db('be_groups')->update('be_groups', ['file_mountpoints' => '2'], ['uid' => self::GROUP]);

        $second = $this->runDescribing([self::IN_DOCS, self::IN_OTHER]);
        self::assertSame(self::NOT_PERMITTED, $second[0], 'run 2 is refused the file of the mount that was taken away');
        self::assertStringStartsWith('File [' . self::IN_OTHER . ']', $second[1], 'run 2 reads the file of the mount that was added');
    }

    // ------------------------------------------------------------------

    /**
     * Accept a task, run it with a model that describes each file in turn,
     * and return what the tool answered for each.
     *
     * @param list<int> $files
     *
     * @return list<string>
     */
    private function runDescribing(array $files): array
    {
        $taskId = self::text($this->taskOf($this->call('SendMessage', ['message' => $this->message('Describe the files.'), 'configuration' => ['returnImmediately' => true]]))['id'] ?? '');
        foreach ($files as $file) {
            $this->responses[] = new CompletionResponse('', 'fake', UsageStatistics::fromTokens(1, 1), toolCalls: [new ToolCall('c-' . $file, 'describe_fal_asset', ['uid' => $file])]);
        }

        $this->responses[] = new CompletionResponse('Done.', 'fake', UsageStatistics::fromTokens(1, 1));
        $this->runTask($taskId);
        $run = $this->runOf($taskId);
        self::assertSame(AgentRunStatus::COMPLETED, $run->statusEnum());

        return $this->toolResultsOf($run, 'describe_fal_asset');
    }

    private function indexFile(int $uid, string $identifier, string $content): void
    {
        file_put_contents($this->instancePath . '/fileadmin' . $identifier, $content);
        $this->db('sys_file')->insert('sys_file', [
            'uid'             => $uid,
            'pid'             => 0,
            'storage'         => 1,
            'identifier'      => $identifier,
            'identifier_hash' => sha1($identifier),
            'folder_hash'     => sha1(dirname($identifier) . '/'),
            'name'            => basename($identifier),
            'extension'       => 'txt',
            'mime_type'       => 'text/plain',
            'size'            => strlen($content),
            'type'            => 1,
            'missing'         => 0,
        ]);
    }

    /**
     * Run a task's queued run on the scripted model, as the worker runs it,
     * with the container's tool registry (and so its gate) and a runtime
     * that resets the container's run-scoped state.
     */
    private function runTask(string $taskId): void
    {
        $registry = $this->getService(ToolRegistry::class);
        $policy   = $this->getService(ToolCallPolicyInterface::class);
        $manager  = self::createStub(LlmServiceManagerInterface::class);
        $next     = function (): CompletionResponse {
            $response = array_shift($this->responses);
            self::assertInstanceOf(CompletionResponse::class, $response, 'The model was asked more often than scripted.');

            return $response;
        };
        $manager->method('chatWithToolsForConfiguration')->willReturnCallback($next);
        $manager->method('chatWithConfiguration')->willReturnCallback($next);
        $loop      = new ToolLoopService($manager, $registry, $policy);
        $persister = $this->getService(AgentRunPersister::class);
        $runtime   = new AgentRuntime(
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
                startPreparers: [$this->getService(A2aFileInputPreparer::class)],
            ),
            toolPolicy: $policy,
            runScopedStates: [$this->runScopedGate()],
        );

        $runtime->runQueued($this->runOf($taskId)->uuid);
    }

    private function runScopedGate(): RunScopedStateInterface
    {
        $gate = $this->getService(FalStorageGate::class);
        self::assertInstanceOf(RunScopedStateInterface::class, $gate);

        return $gate;
    }

    /**
     * @return list<string>
     */
    private function toolResultsOf(AgentRun $run, string $tool): array
    {
        $results = [];
        foreach ($this->getService(AgentRunPersister::class)->findEvents($run->uid) as $event) {
            if ($event->kind === AgentEventKind::TOOL->value && ($event->payload['toolName'] ?? null) === $tool) {
                $results[] = self::text($event->payload['toolResult'] ?? '');
            }
        }

        return $results;
    }

    private function runOf(string $taskId): AgentRun
    {
        $runUid = $this->db(A2aTaskStore::TABLE)->select(['run_uid'], A2aTaskStore::TABLE, ['task_id' => $taskId])->fetchOne();
        $uuid   = $this->db('tx_nrllm_agentrun')->select(['uuid'], 'tx_nrllm_agentrun', ['uid' => $runUid])->fetchOne();
        self::assertIsString($uuid);
        $run = $this->getService(AgentRunPersister::class)->findRun($uuid);
        self::assertInstanceOf(AgentRun::class, $run);

        return $run;
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
}
