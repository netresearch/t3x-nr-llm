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
use Netresearch\NrLlm\Domain\ValueObject\AgentRunReference;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Service\Agent\A2a\File\A2aFileInputPreparer;
use Netresearch\NrLlm\Service\Agent\A2a\File\A2aFileStore;
use Netresearch\NrLlm\Service\Agent\A2a\File\A2aInboundFilePolicy;
use Netresearch\NrLlm\Service\Agent\A2a\File\A2aSendFalFileTool;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aAgentCardBuilder;
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
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * FAL and the A2A server role (spec 051 A2A-FILE6, A2A-FILE7, ADR-284).
 *
 * The technical user 7 reads storage 1 through the file mounts of group 70:
 * mount 1 is `/docs/`, mount 2 is `/other/`. File 101 lies in `/docs/`, file
 * 102 in `/other/`. The group starts with mount 1 only. Profile 1 offers
 * `send_fal_file` and approves its clients as recipients of FAL files; files
 * are on, with a limit of 1024 bytes per file.
 *
 * Every run here runs in this one PHP process with the container's tool
 * registry, and so with the container's {@see FalStorageGate}: the process is
 * the long-running worker of A2A-FILE7.
 *
 * Adopting an inbound file into FAL is tested in A2aFalInboxAdoptionTest
 * (ADR-305).
 */
#[CoversClass(FalStorageGate::class)]
#[CoversClass(AgentRuntime::class)]
#[CoversClass(A2aSendFalFileTool::class)]
#[CoversClass(A2aFileStore::class)]
#[CoversClass(A2aAgentCardBuilder::class)]
final class A2aFalAdoptionTest extends AbstractFunctionalTestCase
{
    use A2aServerFixture;

    private const GROUP = 70;

    private const WORKSPACE = 3;

    /** The workspace record carries the file mount restriction (A2A-FILE6). */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'workspaces'];

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
            // Live access: with EXT:workspaces loaded a user works in live
            // only with this grant.
            'workspace_perms' => 1,
        ]);
        // options=3: the user takes the db and file mounts of its groups.
        $this->db('be_users')->update('be_users', ['usergroup' => (string) self::GROUP, 'options' => 3], ['uid' => 7]);

        $this->indexFile(self::IN_DOCS, '/docs/report.txt', 'The report.');
        $this->indexFile(self::IN_OTHER, '/other/notes.txt', 'The notes.');

        // Profile 1 (`summary`, called by client 1) offers the export and
        // approves its clients as recipients; the tool is switched on.
        $this->fileSettings(true);
        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['tools' => A2aSendFalFileTool::NAME, 'send_fal_files' => 1], ['uid' => 1]);
        $this->db('tx_nrllm_tool_state')->insert('tx_nrllm_tool_state', ['tool_name' => A2aSendFalFileTool::NAME, 'enabled' => 1]);
    }

    protected function tearDown(): void
    {
        $this->serverSettings(null);
        $this->privacyLevel(null);
        $this->fileSettings(null);
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

    /**
     * A2A-FILE6: a run puts a file inside its technical user's mounts into
     * its result; the client receives it as an artifact, and the model reads
     * the file's id, never its content.
     */
    #[Test]
    public function aFileInsideTheTechnicalUsersMountGoesToTheClient(): void
    {
        $taskId          = $this->accepted();
        $this->responses = [$this->callOf(self::IN_DOCS), new CompletionResponse('Done.', 'fake', UsageStatistics::fromTokens(1, 1))];
        $this->runTask($taskId);
        $run = $this->runOf($taskId);
        self::assertSame(AgentRunStatus::COMPLETED, $run->statusEnum());

        $result = $this->toolResultsOf($run, A2aSendFalFileTool::NAME)[0] ?? '';
        self::assertSame(1, preg_match('/^The file report\.txt \(text\/plain, 11 bytes\) goes to the client with the result of this task, as file ([0-9a-f-]{36})\.$/', $result, $matches), $result);
        self::assertStringNotContainsString('The report.', $result);

        $part = $this->filePart($taskId, $matches[1]);
        self::assertSame([base64_encode('The report.'), 'text/plain', 'report.txt'], [$part['raw'] ?? null, $part['mediaType'] ?? null, $part['filename'] ?? null]);
        self::assertSame(1, $this->rows(A2aFileStore::TABLE, ['direction' => A2aFileStore::DIRECTION_OUT, 'origin' => A2aSendFalFileTool::ORIGIN]));
    }

    /**
     * A2A-FILE6: a file outside the technical user's mounts, a uid no file
     * carries and a run without a user get the same answer, and nothing is
     * registered.
     */
    #[Test]
    public function outsideTheMountUnknownAndWithoutAUserGiveOneAnswer(): void
    {
        $run = $this->runReferenceOf($this->accepted());

        $outside = $this->send(self::IN_OTHER, $run);
        $unknown = $this->send(999, $run);
        $noUser  = $this->send(self::IN_DOCS, $run, false);

        self::assertSame(self::NOT_PERMITTED, $outside);
        self::assertSame([$outside, $outside], [$unknown, $noUser]);
        self::assertSame(0, $this->rows(A2aFileStore::TABLE, ['direction' => A2aFileStore::DIRECTION_OUT]));
    }

    /**
     * A2A-FILE6: without the profile's recipient approval no file leaves, and
     * the refusal is the same for a file the user reaches and an unknown one.
     */
    #[Test]
    public function withoutTheRecipientApprovalNoFileLeaves(): void
    {
        $run = $this->runReferenceOf($this->accepted());
        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['send_fal_files' => 0], ['uid' => 1]);

        $inside  = $this->send(self::IN_DOCS, $run);
        $unknown = $this->send(999, $run);

        self::assertSame('Not sent: this skill sends no files from FAL to its clients.', $inside);
        self::assertSame($inside, $unknown);
        self::assertSame(0, $this->rows(A2aFileStore::TABLE, ['direction' => A2aFileStore::DIRECTION_OUT]));

        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['send_fal_files' => 1], ['uid' => 1]);
        self::assertStringStartsWith('The file report.txt', $this->send(self::IN_DOCS, $run), 'the approval is read at the call');
    }

    /**
     * A2A-FILE6, A2A-OPS2: a run that is no A2A task has no client to send
     * to, and with files switched off nothing is sent.
     */
    #[Test]
    public function noTaskAndFilesOffSendNothing(): void
    {
        $run = $this->runReferenceOf($this->accepted());

        $noTask = 'Not sent: this run is no task of the A2A server role, so it has no client to send a file to.';
        self::assertSame($noTask, $this->tool()->execute(['uid' => self::IN_DOCS], new ToolExecutionContext(AiActorContext::backendUser(7, false, [self::GROUP]), $this->technicalUser()))->content, 'no run');
        self::assertSame($noTask, $this->send(self::IN_DOCS, new AgentRunReference($run->uid + 1000, 'not-an-a2a-run')), 'a run without a task');

        $this->fileSettings(false);
        self::assertSame('Not sent: this installation sends no A2A files.', $this->send(self::IN_DOCS, $run));
        self::assertSame(0, $this->rows(A2aFileStore::TABLE, ['direction' => A2aFileStore::DIRECTION_OUT]));
    }

    /**
     * A2A-FILE5, A2A-FILE6: a task whose answer is kept below the full level
     * sends no file, so the tool sends none and says so instead of claiming
     * that the file goes to the client.
     */
    #[Test]
    public function aTaskKeptBelowTheFullLevelSendsNoFile(): void
    {
        $run = $this->runReferenceOf($this->accepted());
        $this->privacyLevel('redacted');

        self::assertSame('Not sent: this task keeps its answer below the full level, and a file cannot be redacted.', $this->send(self::IN_DOCS, $run));
        self::assertSame(0, $this->rows(A2aFileStore::TABLE, ['direction' => A2aFileStore::DIRECTION_OUT]));
    }

    /**
     * A2A-FILE6: in a draft workspace whose record names file mounts, the
     * technical user reaches only its own mounts that the workspace names too,
     * as core intersects them. A file of a group mount the workspace leaves
     * out is refused like a file outside every mount.
     */
    #[Test]
    public function aDraftWorkspaceNarrowsTheFileMounts(): void
    {
        $this->db('be_groups')->update('be_groups', ['file_mountpoints' => '1,2'], ['uid' => self::GROUP]);
        $this->db('sys_workspace')->insert('sys_workspace', ['uid' => self::WORKSPACE, 'pid' => 0, 'title' => 'Draft', 'members' => 'be_users_7', 'file_mountpoints' => '1']);
        $this->db('tx_nrllm_a2a_client')->update('tx_nrllm_a2a_client', ['workspace' => self::WORKSPACE], ['uid' => 1]);
        $run = $this->runReferenceOf($this->accepted());
        self::assertSame(self::WORKSPACE, $this->technicalUser(self::WORKSPACE)->workspace);

        self::assertSame(self::NOT_PERMITTED, $this->send(self::IN_OTHER, $run, true, self::WORKSPACE), 'a group mount the workspace leaves out');
        self::assertStringStartsWith('The file report.txt', $this->send(self::IN_DOCS, $run, true, self::WORKSPACE), 'a group mount the workspace names');

        $this->db('sys_workspace')->update('sys_workspace', ['file_mountpoints' => '1,2'], ['uid' => self::WORKSPACE]);
        $this->getService(FalStorageGate::class)->reset();
        self::assertStringStartsWith('The file notes.txt', $this->send(self::IN_OTHER, $run, true, self::WORKSPACE), 'once the workspace names it too');
    }

    /**
     * A2A-FILE1, A2A-FILE6: a file the user may read is sent only when it is
     * one of the five types, within the size limit and passes its type's
     * check.
     */
    #[Test]
    public function onlyACheckedFileOfTheFiveTypesWithinTheLimitIsSent(): void
    {
        $run = $this->runReferenceOf($this->accepted());
        $this->indexFile(103, '/docs/page.html', '<p>Hello</p>', 'text/html');
        $this->indexFile(104, '/docs/big.txt', str_repeat('x', 2000));
        $this->indexFile(105, '/docs/broken.json', '{"open": ', 'application/json');

        self::assertSame('Not sent: the file page.html is of the type text/html, and only text/plain, application/json, application/pdf, image/png, image/jpeg are sent.', $this->send(103, $run));
        self::assertSame('Not sent: the file big.txt holds 2000 bytes, more than the 1024 an A2A file may hold.', $this->send(104, $run));
        self::assertStringStartsWith('The file is not sent: ', $this->send(105, $run));
        self::assertSame(0, $this->rows(A2aFileStore::TABLE, ['direction' => A2aFileStore::DIRECTION_OUT]));
    }

    /**
     * A2A-FILE6: a call repeated for the same file, as a retried segment
     * makes it, adds the file once.
     */
    #[Test]
    public function sendingTheSameFileTwiceAddsItOnce(): void
    {
        $run = $this->runReferenceOf($this->accepted());

        $first  = $this->send(self::IN_DOCS, $run);
        $second = $this->send(self::IN_DOCS, $run);

        self::assertStringStartsWith('The file report.txt', $first);
        self::assertSame($first, $second);
        self::assertSame(1, $this->rows(A2aFileStore::TABLE, ['direction' => A2aFileStore::DIRECTION_OUT]));
    }

    /**
     * A2A-FILE1, A2A-SV3: a skill that sends FAL files lists the five types
     * among its output modes while files are on; otherwise text/plain only.
     */
    #[Test]
    public function aSkillThatSendsFalFilesListsTheFileTypesAsOutputModes(): void
    {
        self::assertSame(['text/plain', 'application/json', 'application/pdf', 'image/png', 'image/jpeg'], $this->outputModesOf('summary'));
        self::assertSame(['text/plain'], $this->outputModesOf('check'), 'a skill that neither offers the tool nor approves');

        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['send_fal_files' => 0], ['uid' => 1]);
        self::assertSame(['text/plain'], $this->outputModesOf('summary'), 'a skill that offers the tool without the approval');

        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['tools' => '', 'send_fal_files' => 1], ['uid' => 1]);
        self::assertSame(['text/plain'], $this->outputModesOf('summary'), 'a skill that does not offer the tool');

        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['tools' => A2aSendFalFileTool::NAME], ['uid' => 1]);
        $this->fileSettings(false);
        self::assertSame(['text/plain'], $this->outputModesOf('summary'), 'files switched off');
    }

    /**
     * A2A-FILE6: cleanup deletes only the private store's rows. The FAL file
     * a task sent stays, with its record and its bytes, after the store's
     * expiry purge.
     */
    #[Test]
    public function cleanupDeletesOnlyTheStoresRowsNeverAFalFile(): void
    {
        $run = $this->runReferenceOf($this->accepted());
        self::assertStringStartsWith('The file report.txt', $this->send(self::IN_DOCS, $run));

        self::assertGreaterThan(0, $this->getService(A2aFileStore::class)->purgeExpired(time() + 3600 * 24 * 365));

        self::assertSame(0, $this->rows(A2aFileStore::TABLE));
        self::assertSame(1, $this->rows('sys_file', ['uid' => self::IN_DOCS]));
        self::assertSame('The report.', file_get_contents($this->instancePath . '/fileadmin/docs/report.txt'));
    }

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

    /**
     * A task of client 1 for profile 1 whose run waits in the queue.
     */
    private function accepted(): string
    {
        return self::text($this->taskOf($this->call('SendMessage', ['message' => $this->message('Send the report.'), 'configuration' => ['returnImmediately' => true]]))['id'] ?? '');
    }

    private function callOf(int $file): CompletionResponse
    {
        return new CompletionResponse('', 'fake', UsageStatistics::fromTokens(1, 1), toolCalls: [new ToolCall('c-' . $file, A2aSendFalFileTool::NAME, ['uid' => $file])]);
    }

    /**
     * What `send_fal_file` answers for one file, called for the task's run as
     * the technical user, or without a user.
     */
    private function send(int $file, AgentRunReference $run, bool $withUser = true, int $workspace = 0): string
    {
        $actor = AiActorContext::backendUser(7, false, [self::GROUP], [], $workspace);

        return $this->tool()->execute(['uid' => $file], new ToolExecutionContext($actor, $withUser ? $this->technicalUser($workspace) : null, $run))->content;
    }

    private function tool(): A2aSendFalFileTool
    {
        $tool = $this->getService(ToolRegistry::class)->get(A2aSendFalFileTool::NAME);
        self::assertInstanceOf(A2aSendFalFileTool::class, $tool);

        return $tool;
    }

    private function technicalUser(int $workspace = 0): BackendUserAuthentication
    {
        $user = (new ActingBackendUserResolver())->resolve(AiActorContext::backendUser(7, false, [self::GROUP], [], $workspace));
        self::assertInstanceOf(BackendUserAuthentication::class, $user);

        return $user;
    }

    private function runReferenceOf(string $taskId): AgentRunReference
    {
        $run = $this->runOf($taskId);

        return new AgentRunReference($run->uid, $run->uuid);
    }

    /**
     * The part of the task's artifacts that carries a file.
     *
     * @return array<string, mixed>
     */
    private function filePart(string $taskId, string $fileId): array
    {
        $task = $this->taskOf($this->call('GetTask', ['id' => $taskId]));
        foreach (self::rowsOf($task['artifacts'] ?? null) as $artifact) {
            foreach (self::rowsOf($artifact['parts'] ?? null) as $part) {
                $metadata  = is_array($part['metadata'] ?? null) ? $part['metadata'] : [];
                $reference = is_array($metadata[A2aInboundFilePolicy::FILE_EXTENSION] ?? null) ? $metadata[A2aInboundFilePolicy::FILE_EXTENSION] : [];
                if (($reference['fileId'] ?? null) === $fileId) {
                    return $part;
                }
            }
        }

        self::fail('No artifact part carries the file ' . $fileId);
    }

    /**
     * The output modes the agent card lists for one skill.
     *
     * @return list<mixed>
     */
    private function outputModesOf(string $skillId): array
    {
        $response = $this->middleware()->process($this->request('GET', self::BASE . '/.well-known/agent-card.json', []), $this->passThrough());
        $card     = self::objectOf(json_decode((string) $response->getBody(), true));
        foreach (self::rowsOf($card['skills'] ?? null) as $skill) {
            if (($skill['id'] ?? null) === $skillId) {
                self::assertIsArray($skill['outputModes'] ?? null);

                return array_values($skill['outputModes']);
            }
        }

        self::fail('The card lists no skill ' . $skillId);
    }

    /**
     * `a2a.files` on or off, with an inline and a file limit of 1024 bytes;
     * null removes them.
     */
    private function fileSettings(?bool $enabled): void
    {
        // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
        $a2a = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm']['a2a'] ?? [];
        $a2a = is_array($a2a) ? $a2a : [];
        if ($enabled === null) {
            unset($a2a['files'], $a2a['maxInlineArtifactBytes'], $a2a['maxRawPartBytes']);
        } else {
            $a2a['files']                  = ['enabled' => $enabled ? '1' : '0', 'retentionHours' => '1'];
            $a2a['maxInlineArtifactBytes'] = '1024';
            $a2a['maxRawPartBytes']        = '1024';
        }

        // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm']['a2a'] = $a2a;
    }

    private function indexFile(int $uid, string $identifier, string $content, string $mimeType = 'text/plain'): void
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
            'extension'       => pathinfo($identifier, PATHINFO_EXTENSION),
            'mime_type'       => $mimeType,
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
