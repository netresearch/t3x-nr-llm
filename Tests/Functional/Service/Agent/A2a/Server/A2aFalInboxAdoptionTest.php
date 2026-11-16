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
use Netresearch\NrLlm\Service\Agent\A2a\File\A2aAdoptClientFileTool;
use Netresearch\NrLlm\Service\Agent\A2a\File\A2aFalInbox;
use Netresearch\NrLlm\Service\Agent\A2a\File\A2aFileInputPreparer;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aResumeGuard;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aServerRecordSaveHook;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Agent\AgentRunExecutor;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunViewFactory;
use Netresearch\NrLlm\Service\Agent\PendingPause;
use Netresearch\NrLlm\Service\Agent\PendingTurnDigest;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Service\Tool\SchemaPropertyClassifier;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Adopting a file a client sent into FAL (spec 051 A2A-FILE6, ADR-305).
 *
 * Client 1 is pinned to the draft workspace 3 and calls profile 1, which may
 * write, offers `adopt_client_file` and names the inbox `2:/a2a-inbox/`.
 * Storage 2 is a Local storage that is not public, with its base path in a
 * temporary directory outside the web root. The technical user 7 reaches the
 * inbox through mount 3 (writable) of group 70, with the permission to add
 * files. The client sends `report.txt` (text/plain) with its message.
 *
 * Storage 1 (`fileadmin/`, public) and storage 4 (`fileadmin/` again, marked
 * not public, but served by the web server) are the two public targets.
 */
#[CoversClass(A2aAdoptClientFileTool::class)]
#[CoversClass(A2aFalInbox::class)]
#[CoversClass(A2aServerRecordSaveHook::class)]
#[CoversClass(FalStorageGate::class)]
#[CoversClass(ToolLoopService::class)]
final class A2aFalInboxAdoptionTest extends AbstractFunctionalTestCase
{
    use A2aServerFixture;

    private const GROUP = 70;

    private const WORKSPACE = 3;

    private const INBOX = '2:/a2a-inbox/';

    private const CONTENT = 'Client data.';

    private const APPROVER = 1;

    /** The workspace record lets the technical user work in workspace 3. */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'workspaces'];

    /** @var list<CompletionResponse> */
    private array $responses = [];

    /** The base path of storage 2, outside the web root. */
    private string $inboxRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServer();
        $this->privacyLevel('full');
        $this->fileSettings(true);

        $this->inboxRoot = rtrim(sys_get_temp_dir(), '/') . '/nrllm-a2a-inbox-' . bin2hex(random_bytes(4));
        GeneralUtility::mkdir_deep($this->inboxRoot . '/a2a-inbox');
        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/a2a-inbox');
        // A Local storage outside the project must be named here.
        // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
        $GLOBALS['TYPO3_CONF_VARS']['BE']['lockRootPath'] = [$this->inboxRoot . '/'];

        $this->storage(1, 'fileadmin/', 'relative', true);
        $this->storage(2, $this->inboxRoot . '/', 'absolute', false);
        $this->storage(4, 'fileadmin/', 'relative', false);

        $this->db('sys_filemounts')->insert('sys_filemounts', ['uid' => 3, 'pid' => 0, 'title' => 'Inbox', 'identifier' => self::INBOX, 'read_only' => 0]);
        $this->db('be_groups')->insert('be_groups', [
            'uid'              => self::GROUP, 'pid' => 0, 'title' => 'A2A inbox writers', 'file_mountpoints' => '3',
            'file_permissions' => 'readFolder,writeFolder,readFile,addFile',
            'workspace_perms'  => 1,
        ]);
        $this->db('be_users')->update('be_users', ['usergroup' => (string) self::GROUP, 'options' => 3], ['uid' => 7]);
        $this->db('sys_workspace')->insert('sys_workspace', ['uid' => self::WORKSPACE, 'pid' => 0, 'title' => 'Draft', 'members' => 'be_users_7']);
        $this->db('tx_nrllm_a2a_client')->update('tx_nrllm_a2a_client', ['workspace' => self::WORKSPACE], ['uid' => 1]);

        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', [
            'may_write'        => 1,
            'tools'            => A2aAdoptClientFileTool::NAME,
            'input_modes'      => 'text/plain',
            'fal_inbox_folder' => self::INBOX,
        ], ['uid' => 1]);
        $this->db('tx_nrllm_tool_state')->insert('tx_nrllm_tool_state', ['tool_name' => A2aAdoptClientFileTool::NAME, 'enabled' => 1]);
    }

    protected function tearDown(): void
    {
        GeneralUtility::rmdir($this->inboxRoot, true);
        GeneralUtility::rmdir($this->instancePath . '/fileadmin/a2a-inbox', true);
        $this->serverSettings(null);
        $this->privacyLevel(null);
        $this->fileSettings(null);
        $this->getService(CacheManager::class)->getCache('ratelimiter')->flush();
        parent::tearDown();
    }

    /**
     * Approved, the client's file lands in the inbox folder as a new FAL
     * file, written as the client's technical user. Nothing references it,
     * and the task's write list names it as a live record.
     */
    #[Test]
    public function anApprovedAdoptionStoresTheFileInTheInboxAndReferencesNothing(): void
    {
        $run = $this->suspendedForApproval();

        $this->decide($run, true);

        $stored = $this->runOf($run->uuid);
        self::assertSame(AgentRunStatus::COMPLETED, $stored->statusEnum());
        self::assertSame(self::CONTENT, (string) file_get_contents($this->inboxRoot . '/a2a-inbox/report.txt'));

        $file = $this->db('sys_file')->select(['uid', 'storage', 'identifier', 'mime_type'], 'sys_file', ['storage' => 2])->fetchAllAssociative();
        self::assertCount(1, $file);
        self::assertSame(['/a2a-inbox/report.txt', 'text/plain'], [$file[0]['identifier'], $file[0]['mime_type']]);
        self::assertSame(0, $this->rows('sys_file_reference'), 'nothing references the adopted file');

        $result = $this->toolResultsOf($stored)[0] ?? '';
        self::assertStringStartsWith(sprintf("The client's file 1 was adopted as 2:/a2a-inbox/report.txt (sys_file %d", (int) $file[0]['uid']), $result);

        $task = $this->taskOf($this->call('GetTask', ['id' => $this->taskIdOf($run)]));
        self::assertSame(
            sprintf("Writes executed (1), each in the workspace named (0 is live):\n- sys_file:%d in workspace 0 (created)", (int) $file[0]['uid']),
            self::j($task, 'artifacts.1.parts.0.text'),
            'a FAL file has no workspace version: the write list names it as live',
        );
    }

    /**
     * Without the approval nothing is written: the run waits, and a denial
     * leaves the inbox and sys_file as they were.
     */
    #[Test]
    public function withoutApprovalNothingIsWritten(): void
    {
        $run = $this->suspendedForApproval();

        self::assertSame(0, $this->rows('sys_file', ['storage' => 2]), 'waiting for the approval');
        self::assertSame([], $this->inboxFiles());

        $this->decide($run, false);

        self::assertSame(0, $this->rows('sys_file', ['storage' => 2]), 'denied');
        self::assertSame([], $this->inboxFiles());
    }

    /**
     * No folder configured: adoption is off. The call is refused before any
     * approval is asked, and nothing is written.
     */
    #[Test]
    public function withoutAnInboxFolderTheCallIsRefusedBeforeAnyApproval(): void
    {
        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['fal_inbox_folder' => ''], ['uid' => 1]);

        $run = $this->runToEnd();

        self::assertSame(AgentRunStatus::COMPLETED, $run->statusEnum(), 'no approval was asked');
        self::assertSame(['Not adopted: this skill names no inbox folder, so it adopts no file.'], $this->toolResultsOf($run));
        self::assertSame(0, $this->rows('sys_file'));
        self::assertSame([], $this->inboxFiles());
    }

    /**
     * A public target is refused where the profile is saved: a public
     * storage, a storage whose base path the web server serves although it is
     * marked not public, storage 0, an unknown or deleted storage, a Local
     * storage without a base path, a path with a parent segment and a
     * malformed identifier. A non-public folder outside the web root is
     * stored.
     */
    #[Test]
    public function aPublicTargetIsRefusedWhenTheProfileIsSaved(): void
    {
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($this->setUpBackendUser(self::APPROVER));
        $this->db('tx_nrllm_a2a_profile')->update('tx_nrllm_a2a_profile', ['fal_inbox_folder' => ''], ['uid' => 1]);
        $this->storage(5, $this->inboxRoot . '/', 'absolute', false);
        $this->db('sys_file_storage')->update('sys_file_storage', ['deleted' => 1], ['uid' => 5]);
        $this->storage(6, '', 'absolute', false);

        $cases = [
            '1:/a2a-inbox/'      => 'public storage',
            '4:/a2a-inbox/'      => 'web root',
            '0:/a2a-inbox/'      => 'storage 0',
            '9:/a2a-inbox/'      => 'unknown storage',
            '5:/a2a-inbox/'      => 'deleted storage',
            '6:/a2a-inbox/'      => 'no base path',
            '2:/a2a-inbox/../x/' => 'a parent segment',
            'a2a-inbox'          => 'no storage',
        ];
        // A worker's working directory may lie outside the web root, where an
        // empty path would resolve to.
        $cwd = (string) getcwd();
        chdir(sys_get_temp_dir());

        try {
            foreach ($cases as $target => $case) {
                $this->dataHandler(['tx_nrllm_a2a_profile' => [1 => ['fal_inbox_folder' => $target]]]);
                self::assertSame('', $this->inboxOf(1), $case);
            }
        } finally {
            chdir($cwd);
        }

        $this->dataHandler(['tx_nrllm_a2a_profile' => [1 => ['fal_inbox_folder' => self::INBOX]]]);
        self::assertSame(self::INBOX, $this->inboxOf(1), 'a non-public folder outside the web root');
    }

    /**
     * The target is asked again at each call: a storage made public after the
     * profile was saved refuses the adoption before any approval.
     */
    #[Test]
    public function aTargetThatBecamePublicIsRefusedAtTheCall(): void
    {
        $this->db('sys_file_storage')->update('sys_file_storage', ['is_public' => 1], ['uid' => 2]);

        $run = $this->runToEnd();

        self::assertSame(AgentRunStatus::COMPLETED, $run->statusEnum());
        self::assertStringStartsWith('Not adopted: the inbox folder of this skill cannot be written to:', $this->toolResultsOf($run)[0] ?? '');
        self::assertSame(0, $this->rows('sys_file'));
    }

    /**
     * Never overwrite: a name already taken in the inbox gives the new file
     * another name, and the existing file keeps its bytes and its record.
     */
    #[Test]
    public function aNameCollisionRenamesTheNewFileAndLeavesTheOldOneUntouched(): void
    {
        file_put_contents($this->inboxRoot . '/a2a-inbox/report.txt', 'Original.');
        $this->db('sys_file')->insert('sys_file', [
            'uid'        => 201, 'pid' => 0, 'storage' => 2,
            'identifier' => '/a2a-inbox/report.txt', 'identifier_hash' => sha1('/a2a-inbox/report.txt'), 'folder_hash' => sha1('/a2a-inbox/'),
            'name'       => 'report.txt', 'extension' => 'txt', 'mime_type' => 'text/plain', 'size' => 9, 'sha1' => sha1('Original.'),
            'type'       => 1, 'missing' => 0, 'tstamp' => 1700000000, 'creation_date' => 1700000000, 'modification_date' => 1700000000,
        ]);
        $before = $this->db('sys_file')->select(['*'], 'sys_file', ['uid' => 201])->fetchAssociative();

        $this->decide($this->suspendedForApproval(), true);

        self::assertSame('Original.', (string) file_get_contents($this->inboxRoot . '/a2a-inbox/report.txt'));
        self::assertSame($before, $this->db('sys_file')->select(['*'], 'sys_file', ['uid' => 201])->fetchAssociative());

        $new = $this->db('sys_file')->select(['identifier'], 'sys_file', ['storage' => 2, 'name' => 'report_01.txt'])->fetchOne();
        self::assertSame('/a2a-inbox/report_01.txt', $new);
        self::assertSame(self::CONTENT, (string) file_get_contents($this->inboxRoot . '/a2a-inbox/report_01.txt'));
    }

    /**
     * The client's technical user writes, with its own file mounts and file
     * permissions: a user without a mount on the inbox, with a read-only one,
     * or without the permission to add files is refused before any approval.
     */
    #[Test]
    public function aUserWithoutAWritableMountOnTheInboxIsRefused(): void
    {
        $this->db('be_groups')->update('be_groups', ['file_mountpoints' => ''], ['uid' => self::GROUP]);
        $none = $this->runToEnd();

        $this->db('be_groups')->update('be_groups', ['file_mountpoints' => '3'], ['uid' => self::GROUP]);
        $this->db('sys_filemounts')->update('sys_filemounts', ['read_only' => 1], ['uid' => 3]);
        $readOnly = $this->runToEnd();

        $this->db('sys_filemounts')->update('sys_filemounts', ['read_only' => 0], ['uid' => 3]);
        $this->db('be_groups')->update('be_groups', ['file_permissions' => 'readFolder,writeFolder,readFile'], ['uid' => self::GROUP]);
        $noAdd = $this->runToEnd();

        $refusal = 'Not adopted: the technical user of this client may not add a file to the inbox folder, or the folder does not exist.';
        self::assertSame([[$refusal], [$refusal], [$refusal]], [$this->toolResultsOf($none), $this->toolResultsOf($readOnly), $this->toolResultsOf($noAdd)]);
        self::assertSame(0, $this->rows('sys_file'));
        self::assertSame([], $this->inboxFiles());
    }

    /**
     * No admin bypass: the mount is taken away while the run waits, and an
     * administrator approves from the backend. The write is still the
     * technical user's, and that user may no longer add the file.
     */
    #[Test]
    public function anAdministratorsApprovalDoesNotLendItsRights(): void
    {
        $run = $this->suspendedForApproval();
        $this->db('be_groups')->update('be_groups', ['file_mountpoints' => ''], ['uid' => self::GROUP]);
        // The approving administrator could write to the inbox itself.
        $this->db('be_groups')->insert('be_groups', ['uid' => 71, 'pid' => 0, 'title' => 'Inbox admins', 'file_mountpoints' => '3', 'file_permissions' => 'readFolder,writeFolder,readFile,addFile', 'workspace_perms' => 1]);
        $this->db('be_users')->update('be_users', ['usergroup' => '71', 'options' => 3], ['uid' => self::APPROVER]);

        $this->decide($run, true);

        self::assertSame(
            ['Not adopted: the technical user of this client may not add a file to the inbox folder, or the folder does not exist.'],
            $this->toolResultsOf($this->runOf($run->uuid)),
        );
        self::assertSame(0, $this->rows('sys_file', ['storage' => 2]));
        self::assertSame([], $this->inboxFiles());
    }

    /**
     * A run that is no A2A task holds no client file, and with files
     * switched off nothing is adopted; both are refused before any approval.
     */
    #[Test]
    public function noTaskAndFilesOffAreRefusedBeforeAnyApproval(): void
    {
        $task = $this->accepted();
        $run  = $this->runOf($this->runUuidOf($task));
        $tool = $this->tool();

        $context = new ToolExecutionContext(AiActorContext::backendUser(7, false, [self::GROUP], [], self::WORKSPACE), $this->technicalUser(), new AgentRunReference($run->uid + 1000, 'not-an-a2a-run'));
        self::assertSame('Not adopted: this run is no task of the A2A server role, so it holds no file from a client.', $tool->refusalBeforeApproval(['file' => 1], $context)?->content);

        $this->fileSettings(false);
        $context = new ToolExecutionContext(AiActorContext::backendUser(7, false, [self::GROUP], [], self::WORKSPACE), $this->technicalUser(), new AgentRunReference($run->uid, $run->uuid));
        self::assertSame('Not adopted: this installation takes no A2A files.', $tool->refusalBeforeApproval(['file' => 1], $context)?->content);
        self::assertSame('Not adopted: this installation takes no A2A files.', $tool->execute(['file' => 1], $context)->content);
        self::assertSame(0, $this->rows('sys_file'));
    }

    /**
     * Only a file the task still holds, checked and approved, is adopted. An
     * ended task gives up its input bytes (A2A-FILE4); a file the store
     * refused or did not pass, or one not of the five types, stays refused;
     * a number the client sent no file for names nothing. All are refused before any approval.
     */
    #[Test]
    public function onlyAFileTheTaskStillHoldsCheckedAndApprovedIsAdopted(): void
    {
        $task    = $this->accepted();
        $run     = $this->runOf($this->runUuidOf($task));
        $context = new ToolExecutionContext(AiActorContext::backendUser(7, false, [self::GROUP], [], self::WORKSPACE), $this->technicalUser(), new AgentRunReference($run->uid, $run->uuid));
        self::assertNull($this->tool()->refusalBeforeApproval(['file' => 1], $context), 'the file as the client sent it');
        self::assertSame('Not adopted: this task holds no checked file 2 from the client.', $this->tool()->refusalBeforeApproval(['file' => 2], $context)?->content);

        $refusal = 'Not adopted: this task holds no checked file 1 from the client.';
        foreach (['approval_state' => ['refused', 'approved'], 'check_state' => ['failed', 'passed'], 'media_type' => ['text/html', 'text/plain']] as $field => [$changed, $original]) {
            $this->db('tx_nrllm_a2a_file')->update('tx_nrllm_a2a_file', [$field => $changed], ['direction' => 'in']);
            self::assertSame($refusal, $this->tool()->refusalBeforeApproval(['file' => 1], $context)?->content, $field);
            $this->db('tx_nrllm_a2a_file')->update('tx_nrllm_a2a_file', [$field => $original], ['direction' => 'in']);
        }

        // What the task's end does to its input files (A2aTaskStore).
        $this->db('tx_nrllm_a2a_file')->update('tx_nrllm_a2a_file', ['content' => ''], ['direction' => 'in']);
        self::assertSame('Not adopted: this task holds no checked file 1 from the client.', $this->tool()->refusalBeforeApproval(['file' => 1], $context)?->content, 'the task ended');
        self::assertSame(0, $this->rows('sys_file'));
    }

    /**
     * The bytes are checked again when the approved call runs: a file that
     * expired while the run waited, or whose bytes no longer match their
     * digest or their size, is not adopted.
     */
    #[Test]
    public function anExpiredOrAlteredFileIsNotAdoptedAfterTheApproval(): void
    {
        $expired = $this->suspendedForApproval();
        $this->db('tx_nrllm_a2a_file')->update('tx_nrllm_a2a_file', ['expires' => time() - 1], ['direction' => 'in']);
        $this->decide($expired, true);

        $altered = $this->suspendedForApproval();
        $this->db('tx_nrllm_a2a_file')->update('tx_nrllm_a2a_file', ['sha256' => hash('sha256', 'Other data.')], ['task_uid' => $this->taskUidOf($altered), 'direction' => 'in']);
        $this->decide($altered, true);

        $resized = $this->suspendedForApproval();
        $this->db('tx_nrllm_a2a_file')->update('tx_nrllm_a2a_file', ['size' => 99], ['task_uid' => $this->taskUidOf($resized), 'direction' => 'in']);
        $this->decide($resized, true);

        $gone = ['Not adopted: the file 1 is no longer held; it expired, or its bytes changed.'];
        self::assertSame(
            [$gone, $gone, $gone],
            [$this->toolResultsOf($this->runOf($expired->uuid)), $this->toolResultsOf($this->runOf($altered->uuid)), $this->toolResultsOf($this->runOf($resized->uuid))],
        );
        self::assertSame(0, $this->rows('sys_file', ['storage' => 2]));
        self::assertSame([], $this->inboxFiles());
    }

    /**
     * The client names the file, but only its base name is kept, with an
     * extension of the type the store checked: a client cannot place a file
     * elsewhere or give it an extension the web server would execute.
     */
    #[Test]
    public function theStoredNameKeepsOnlyTheBaseNameAndTheCheckedTypesExtension(): void
    {
        $this->decide($this->suspendedForApproval('../../evil.php'), true);

        self::assertSame(['evil.txt'], $this->inboxFiles());
        self::assertSame('/a2a-inbox/evil.txt', $this->db('sys_file')->select(['identifier'], 'sys_file', ['storage' => 2])->fetchOne());
    }

    /**
     * The model names a file by the number the run was told ("File N of M"),
     * so file 2 of a message with two files is the second one.
     */
    #[Test]
    public function theNumberNamesTheFileInTheOrderTheRunWasTold(): void
    {
        $this->decide($this->suspendedForApproval('report.txt', 2, 'Second data.'), true);

        self::assertSame(['second.txt'], $this->inboxFiles());
        self::assertSame('Second data.', (string) file_get_contents($this->inboxRoot . '/a2a-inbox/second.txt'));
    }

    /**
     * The approvals-inbox card names the call: the tool and the file's
     * number, with no preview of the file.
     */
    #[Test]
    public function theApprovalCardNamesTheToolAndTheFileNumber(): void
    {
        $card = $this->card($this->suspendedForApproval());

        self::assertCount(1, $card->pendingCalls);
        self::assertSame(A2aAdoptClientFileTool::NAME, $card->pendingCalls[0]->name);
        self::assertSame(['file' => 1], json_decode($card->pendingCalls[0]->argumentsJson, true));
        self::assertSame([], $card->pendingCalls[0]->previewLines);
    }

    /**
     * A decision stored through the durable intake and carried out by the
     * worker adopts like one taken in the inbox, and like it lends the
     * approving administrator's rights to nobody.
     */
    #[Test]
    public function aDurableDecisionAdoptsAsTheTechnicalUserToo(): void
    {
        $this->decideDurably($this->suspendedForApproval());
        self::assertSame(['report.txt'], $this->inboxFiles());

        $run = $this->suspendedForApproval('notes.txt');
        $this->db('be_groups')->update('be_groups', ['file_mountpoints' => ''], ['uid' => self::GROUP]);
        $this->db('be_groups')->insert('be_groups', ['uid' => 71, 'pid' => 0, 'title' => 'Inbox admins', 'file_mountpoints' => '3', 'file_permissions' => 'readFolder,writeFolder,readFile,addFile', 'workspace_perms' => 1]);
        $this->db('be_users')->update('be_users', ['usergroup' => '71', 'options' => 3], ['uid' => self::APPROVER]);
        $this->setUpBackendUser(self::APPROVER);
        $this->decideDurably($run);

        self::assertSame(['report.txt'], $this->inboxFiles());
        self::assertSame(
            ['Not adopted: the technical user of this client may not add a file to the inbox folder, or the folder does not exist.'],
            $this->toolResultsOf($this->runOf($run->uuid)),
        );
    }

    // ------------------------------------------------------------------

    private function taskUidOf(AgentRun $run): int
    {
        $uid = $this->db(A2aTaskStore::TABLE)->select(['uid'], A2aTaskStore::TABLE, ['run_uid' => $run->uid])->fetchOne();

        return is_numeric($uid) ? (int) $uid : 0;
    }

    /**
     * A task whose run asked to adopt the client's file and waits for the
     * approval.
     */
    private function suspendedForApproval(string $filename = 'report.txt', int $file = 1, string $second = ''): AgentRun
    {
        $taskId          = $this->accepted($filename, $second);
        $this->responses = [$this->adoptCall($file)];
        $this->runtime()->runQueued($this->runUuidOf($taskId));
        $run = $this->runOf($this->runUuidOf($taskId));
        self::assertSame(AgentRunStatus::WAITING_FOR_APPROVAL, $run->statusEnum());

        return $run;
    }

    /**
     * A task run to its end, where the adoption is refused before an approval
     * would be asked.
     */
    private function runToEnd(): AgentRun
    {
        $taskId          = $this->accepted();
        $this->responses = [$this->adoptCall(), new CompletionResponse('Done.', 'fake', UsageStatistics::fromTokens(1, 1))];
        $this->runtime()->runQueued($this->runUuidOf($taskId));

        return $this->runOf($this->runUuidOf($taskId));
    }

    /**
     * An administrator decides from the backend, as the ambient user.
     */
    private function decide(AgentRun $run, bool $approved): void
    {
        $this->setUpBackendUser(self::APPROVER);
        $digest = (new WaitingRunViewFactory(
            $this->getService(ToolRegistry::class),
            new SchemaPropertyClassifier(),
            new PendingTurnDigest(),
            new ApprovalPreviewTranslator($this->getService(LanguageServiceFactory::class)),
        ))->buildWaiting([$run], $GLOBALS['BE_USER'])[0]->turnDigest;
        self::assertIsString($digest);

        $this->responses = [new CompletionResponse('Done.', 'fake', UsageStatistics::fromTokens(1, 1))];
        $this->runtime()->approve(AiActorContext::backendUser(self::APPROVER, isAdmin: true), $run->uuid, new ApprovalDecision($approved, self::APPROVER, $digest));
    }

    private function adoptCall(int $file = 1): CompletionResponse
    {
        return new CompletionResponse('', 'fake', UsageStatistics::fromTokens(1, 1), toolCalls: [new ToolCall('c-adopt', A2aAdoptClientFileTool::NAME, ['file' => $file])]);
    }

    /**
     * The approvals-inbox card of a waiting run, as the administrator sees it.
     */
    private function card(AgentRun $run): WaitingRunView
    {
        $this->setUpBackendUser(self::APPROVER);
        $views = (new WaitingRunViewFactory(
            $this->getService(ToolRegistry::class),
            new SchemaPropertyClassifier(),
            new PendingTurnDigest(),
            new ApprovalPreviewTranslator($this->getService(LanguageServiceFactory::class)),
        ))->buildWaiting([$run], $GLOBALS['BE_USER']);
        self::assertCount(1, $views);

        return $views[0];
    }

    /**
     * An administrator decides through the durable decision intake
     * (ADR-281), and the worker carries the decision out.
     */
    private function decideDurably(AgentRun $run): void
    {
        $actor   = AiActorContext::backendUser(self::APPROVER, isAdmin: true);
        $runtime = $this->runtime();
        $pause   = $runtime->pendingPause($actor, $run->uuid);
        self::assertInstanceOf(PendingPause::class, $pause);
        $runtime->storeApproval($actor, $run->uuid, new ApprovalDecision(true, self::APPROVER, $pause->turnDigest), $pause->generation);

        $this->responses = [new CompletionResponse('Done.', 'fake', UsageStatistics::fromTokens(1, 1))];
        $this->runtime()->runQueued($run->uuid);
    }

    /**
     * A task of client 1 for profile 1 that carries the client's file.
     */
    private function accepted(string $filename = 'report.txt', string $second = ''): string
    {
        $parts = [
            ['text' => 'Keep the report.'],
            ['raw' => base64_encode(self::CONTENT), 'mediaType' => 'text/plain', 'filename' => $filename],
        ];
        if ($second !== '') {
            $parts[] = ['raw' => base64_encode($second), 'mediaType' => 'text/plain', 'filename' => 'second.txt'];
        }

        $message = ['messageId' => bin2hex(random_bytes(8)), 'role' => 'ROLE_USER', 'parts' => $parts];
        $task    = $this->taskOf($this->call('SendMessage', ['message' => $message, 'configuration' => ['returnImmediately' => true]]));
        self::assertNotSame('', self::text($task['id'] ?? ''), 'the task was accepted');

        return self::text($task['id'] ?? '');
    }

    /**
     * A runtime as the worker builds it, on the scripted model, with the
     * container's tool registry and gate.
     */
    private function runtime(): AgentRuntime
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
        // With the container's dispatcher, so the task records the write.
        $loop      = new ToolLoopService($manager, $registry, $policy, eventDispatcher: $this->getService(EventDispatcherInterface::class));
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
                startPreparers: [$this->getService(A2aFileInputPreparer::class)],
            ),
            toolPolicy: $policy,
            runScopedStates: [$this->getService(FalStorageGate::class)],
        );
    }

    private function tool(): A2aAdoptClientFileTool
    {
        $tool = $this->getService(ToolRegistry::class)->get(A2aAdoptClientFileTool::NAME);
        self::assertInstanceOf(A2aAdoptClientFileTool::class, $tool);

        return $tool;
    }

    private function technicalUser(): BackendUserAuthentication
    {
        $user = (new ActingBackendUserResolver())->resolve(AiActorContext::backendUser(7, false, [self::GROUP], [], self::WORKSPACE));
        self::assertInstanceOf(BackendUserAuthentication::class, $user);

        return $user;
    }

    /**
     * @return list<string>
     */
    private function toolResultsOf(AgentRun $run): array
    {
        $results = [];
        foreach ($this->getService(AgentRunPersister::class)->findEvents($run->uid) as $event) {
            if ($event->kind === AgentEventKind::TOOL->value && ($event->payload['toolName'] ?? null) === A2aAdoptClientFileTool::NAME) {
                $results[] = self::text($event->payload['toolResult'] ?? '');
            }
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    private function inboxFiles(): array
    {
        return array_values(array_diff(scandir($this->inboxRoot . '/a2a-inbox') ?: [], ['.', '..']));
    }

    private function inboxOf(int $profile): string
    {
        return self::text($this->db('tx_nrllm_a2a_profile')->select(['fal_inbox_folder'], 'tx_nrllm_a2a_profile', ['uid' => $profile])->fetchOne());
    }

    private function runUuidOf(string $taskId): string
    {
        $runUid = $this->db(A2aTaskStore::TABLE)->select(['run_uid'], A2aTaskStore::TABLE, ['task_id' => $taskId])->fetchOne();

        return self::text($this->db('tx_nrllm_agentrun')->select(['uuid'], 'tx_nrllm_agentrun', ['uid' => $runUid])->fetchOne());
    }

    private function taskIdOf(AgentRun $run): string
    {
        return self::text($this->db(A2aTaskStore::TABLE)->select(['task_id'], A2aTaskStore::TABLE, ['run_uid' => $run->uid])->fetchOne());
    }

    private function runOf(string $uuid): AgentRun
    {
        $run = $this->getService(AgentRunPersister::class)->findRun($uuid);
        self::assertInstanceOf(AgentRun::class, $run);

        return $run;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $data
     */
    private function dataHandler(array $data): void
    {
        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start($data, []);
        $handler->process_datamap();
    }

    private function storage(int $uid, string $basePath, string $pathType, bool $public): void
    {
        $configuration = '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>
<T3FlexForms>
    <data>
        <sheet index="sDEF">
            <language index="lDEF">
                <field index="basePath"><value index="vDEF">' . htmlspecialchars($basePath) . '</value></field>
                <field index="pathType"><value index="vDEF">' . $pathType . '</value></field>
                <field index="caseSensitive"><value index="vDEF">1</value></field>
            </language>
        </sheet>
    </data>
</T3FlexForms>';
        $this->db('sys_file_storage')->insert('sys_file_storage', [
            'uid'           => $uid, 'pid' => 0, 'name' => 'Storage ' . $uid, 'driver' => 'Local',
            'configuration' => $configuration,
            'is_online'     => 1, 'is_browsable' => 1, 'is_public' => $public ? 1 : 0, 'is_writable' => 1,
        ]);
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
