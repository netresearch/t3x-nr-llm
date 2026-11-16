<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\File;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Enum\WriteCompleteness;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunReference;
use Netresearch\NrLlm\Domain\ValueObject\PendingWriteTarget;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSkillProfile;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSkillProfileRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewLabel;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Service\Tool\PendingTargetInterface;
use Netresearch\NrLlm\Service\Tool\RefusesBeforeApprovalInterface;
use Netresearch\NrLlm\Service\Tool\Scope\ScopedDestination;
use Netresearch\NrLlm\Service\Tool\Scope\ScopedWrite;
use Netresearch\NrLlm\Service\Tool\Scope\ScopedWriteInterface;
use Netresearch\NrLlm\Service\Tool\StoredPreviewVisibilityInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * `adopt_client_file`: a run in the A2A server role stores ONE file its
 * client sent with the task in FAL (spec 051 A2A-FILE6, ADR-305).
 *
 * Built as the owner decided, and restricted to that:
 *
 * 1. The target is the inbox folder of the task's publication profile
 *    (`tx_nrllm_a2a_profile.fal_inbox_folder`), never one the client or the
 *    model names. No folder: adoption is off. A public target is refused
 *    ({@see A2aFalInbox}).
 * 2. The file is written as the run's acting user, the client's technical
 *    backend user, through a storage that carries that user's file mounts
 *    and permissions ({@see FalStorageGate::folderToAddFilesTo()}).
 * 3. The tool declares a write, so every call waits for the approval of a
 *    person of this installation, as every writing tool does
 *    ({@see \Netresearch\NrLlm\Service\Tool\ToolApprovalRule}).
 * 4. A name that is taken is never overwritten: core gives the new file
 *    another name ({@see DuplicationBehavior::RENAME}).
 * 5. Nothing references the file. It changes no published content until an
 *    editor uses it in a workspace record.
 *
 * The model names the file by its number, the N of the "File N of M" line
 * the run was given ({@see A2aFileInputPreparer}); the file keeps the name
 * the client gave it, without format characters, with the extension of its
 * checked type, and with core's `_01` to `_99` suffix when the name is
 * taken. The bytes are the stored copy, checked again against their digest:
 * they exist only until the task ends or the file expires.
 *
 * The approval card shows the client's name, the name the file is stored
 * as, the checked type, the size, a short digest and the inbox folder
 * ({@see ToolPreviewInterface}). Those lines are the reservation of
 * ADR-184: when they read differently at execution, because the folder, the
 * free name or the file changed, the call is not carried out and the run
 * waits for a new approval.
 *
 * Every refusal the configuration or the user's rights decide comes before
 * the approval ({@see RefusesBeforeApprovalInterface}), so no card asks a
 * person to decide a call that cannot be written; `execute()` asks the same
 * first.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aAdoptClientFileTool implements ToolInterface, ToolEffectInterface, RefusesBeforeApprovalInterface, PendingTargetInterface, ScopedWriteInterface, ToolPreviewInterface, StoredPreviewVisibilityInterface
{
    use SafeCastTrait;

    public const NAME = 'adopt_client_file';

    /** The extension each of the five types is stored with. */
    private const EXTENSIONS = [
        'text/plain'       => ['txt'],
        'application/json' => ['json'],
        'application/pdf'  => ['pdf'],
        'image/png'        => ['png'],
        'image/jpeg'       => ['jpg', 'jpeg'],
    ];

    private const NO_TASK = 'Not adopted: this run is no task of the A2A server role, so it holds no file from a client.';

    private const FILES_OFF = 'Not adopted: this installation takes no A2A files.';

    private const NO_INBOX = 'Not adopted: this skill names no inbox folder, so it adopts no file.';

    private const NO_FILE = 'Not adopted: this task holds no checked file %d from the client.';

    private const NOT_WRITABLE = 'Not adopted: the technical user of this client may not add a file to the inbox folder, or the folder does not exist.';

    private const GONE = 'Not adopted: the file %d is no longer held; it expired, or its bytes changed.';

    private const NO_FREE_NAME = 'Not adopted: the inbox folder holds no free name for the file %s.';

    private const PARTIAL = 'Not completed: TYPO3 failed after the file was written. A file named %s may remain in the inbox folder %s; nothing references it.';

    private const NOT_APPROVED = 'Not adopted: the call was not approved on a card that named the file and its name.';

    private const TAKEN = 'Not adopted: the name %s that was approved for this file is taken now. Nothing was written under another name; adopt the file again to approve a new name.';

    public function __construct(
        private ConnectionPool $connectionPool,
        private FalStorageGate $storageGate,
        private A2aFileStore $fileStore,
        private A2aFileSettings $fileSettings,
        private A2aSkillProfileRepository $profiles,
        private A2aFalInbox $inbox,
        private ApprovalPreviewTranslator $translator,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            self::NAME,
            "Store ONE file the client sent with this A2A task in this installation's file storage (FAL), in the "
            . "inbox folder this skill names. Name the file by its number: the N of the line 'File N of M from the "
            . "client'. A person of this installation approves the call first. An existing file of the same name is "
            . 'kept and the new one is renamed. Nothing uses the stored file until an editor references it.',
            [
                'type'       => 'object',
                'properties' => [
                    'file' => [
                        'type'        => 'integer',
                        'description' => 'The number of the client file, starting at 1.',
                    ],
                ],
                'required' => ['file'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        // The refusal before the approval, asked again: the plan it is made of.
        $plan = $this->plan($arguments, $context);
        if (is_string($plan)) {
            return ToolResult::error($plan);
        }

        $number = self::toInt($arguments['file'] ?? 0);
        $row    = $plan['row'];
        $bytes  = $this->fileStore->content(self::toInt($row['uid'] ?? 0), $plan['owner']);
        if (self::toInt($row['expires'] ?? 0) <= time()
            || !hash_equals(self::toStr($row['sha256'] ?? ''), hash('sha256', $bytes))) {
            return ToolResult::error(sprintf(self::GONE, $number));
        }

        // The name the approver saw. Another call of the same turn may have
        // taken it since the card was compared (ADR-184 compares the whole
        // turn before any call runs); the file is then not written under a
        // name that was not on its card.
        $name = $this->approvedName($arguments, $context, $plan);
        if ($name === null) {
            return ToolResult::error(self::NOT_APPROVED);
        }

        if ($this->taken($plan['folder'], $name)) {
            return ToolResult::error(sprintf(self::TAKEN, $name));
        }

        $mediaType = self::toStr($row['media_type'] ?? '');
        $local     = GeneralUtility::tempnam('nrllm_a2a_adopt_');

        try {
            if (file_put_contents($local, $bytes) !== strlen($bytes)) {
                return ToolResult::error('Not adopted: the file could not be written.');
            }

            // CANCEL: never another name, never over an existing file.
            $file = $plan['folder']->addFile($local, $name, DuplicationBehavior::CANCEL);
        } catch (ExistingTargetFileNameException) {
            return ToolResult::error(sprintf(self::TAKEN, $name));
        } catch (Throwable) {
            // Core's message can name server paths; the model and the client
            // read this answer. A file core wrote before it failed is named,
            // not deleted: the name was free when the call started, but
            // deleting by name could remove a file another writer put there
            // meanwhile, and an unreferenced file in the inbox changes nothing.
            return ToolResult::error($this->written($plan['folder'], $name)
                ? sprintf(self::PARTIAL, $name, $plan['target'])
                : 'Not adopted: TYPO3 refused to add the file to the inbox folder.');
        } finally {
            if (is_file($local)) {
                // $local is the temporary file tempnam() created above; no
                // client or model input reaches the path.
                // nosemgrep: php.lang.security.unlink-use.unlink-use
                unlink($local);
            }
        }

        return ToolResult::text(sprintf(
            "The client's file %d was adopted as %d:%s (sys_file %d, %s, %d bytes). Nothing references it: it takes effect only when an editor uses it in a workspace record.",
            $number,
            $file->getStorage()->getUid(),
            $file->getIdentifier(),
            $file->getUid(),
            $mediaType,
            strlen($bytes),
        ))->withWriteTarget(new RecordReference('sys_file', $file->getUid()), WriteKind::CREATED, WriteCompleteness::COMPLETE);
    }

    /**
     * The refusals the configuration and the user's rights decide, asked
     * before the approval and again by `execute()`. Reads only, no clock.
     */
    public function refusalBeforeApproval(array $arguments, ToolExecutionContext $context): ?ToolResult
    {
        $plan = $this->plan($arguments, $context);

        return is_string($plan) ? ToolResult::error($plan) : null;
    }

    /**
     * What the approver decides on: the client's name and the stored name,
     * the checked type, the size, a short digest and the inbox folder. Read
     * again at execution and compared (ADR-184).
     */
    public function previewCall(array $arguments, ToolExecutionContext $context): array
    {
        $plan = $this->plan($arguments, $context);
        if (is_string($plan)) {
            return [$plan];
        }

        $user = $context->actingBackendUser();
        $t    = fn (ApprovalPreviewLabel $label, int|string ...$values): string => $this->translator->text($user, $label, ...$values);
        $row  = $plan['row'];

        return [
            $t(ApprovalPreviewLabel::AdoptClientFileHeading),
            $this->nameLine($user, $arguments, $row, $plan['name']),
            $t(ApprovalPreviewLabel::AdoptClientFileContent, self::toStr($row['media_type'] ?? ''), self::toInt($row['size'] ?? 0), substr(self::toStr($row['sha256'] ?? ''), 0, 12)),
            $t(ApprovalPreviewLabel::AdoptClientFileTarget, $plan['target']),
        ];
    }

    /**
     * Asked by nothing for this tool: the inbox decides on the stored lines
     * ({@see self::mayViewerReadStoredPreview()}), because the arguments do
     * not name the folder the card shows. Withheld if anything asks.
     */
    public function mayViewerReadPreview(array $arguments, BackendUserAuthentication $viewer): bool
    {
        return false;
    }

    /**
     * The canonical inbox folder the preview shows, stored with it as a field
     * of its own; null when the call cannot be written.
     */
    public function previewSubject(array $arguments, ToolExecutionContext $context): ?string
    {
        $plan = $this->plan($arguments, $context);

        return is_array($plan) ? $plan['target'] : null;
    }

    /**
     * Only a viewer who may read the inbox folder the card was taken for sees
     * it: an administrator, or a user whose file mounts reach that folder.
     * The folder is the stored subject, never text of the lines, which carry
     * the client's file name; not the profile, which may name another folder
     * by now. No subject, or one that is no folder identifier: withheld.
     */
    public function mayViewerReadStoredPreview(array $arguments, ?string $subject, BackendUserAuthentication $viewer): bool
    {
        $target = $subject !== null ? A2aFalInbox::parse($subject) : null;

        return $target !== null && $this->storageGate->isFolderReadableBy($viewer, $target['storage'], $target['folder']);
    }

    public function getEffect(): ToolEffect
    {
        // A second call stores a second file under another name.
        return ToolEffect::NON_IDEMPOTENT_WRITE;
    }

    /**
     * An adoption creates its record, so a pending call names none (ADR-214).
     */
    public function pendingTarget(array $arguments): ?PendingWriteTarget
    {
        return null;
    }

    /**
     * What the call touches, for the scope of a run's assignment (ADR-295): a
     * new `sys_file` outside the page tree, and a file in a storage, which a
     * rollback cannot take back, so a scoped run refuses it.
     *
     * @param array<string, mixed> $arguments
     */
    public function scopedWrite(array $arguments): ScopedWrite
    {
        return new ScopedWrite('create', [], [new ScopedDestination('sys_file', 0)], writesFiles: true);
    }

    public function isEnabledByDefault(): bool
    {
        // Off until an administrator switches it on: a file of a remote
        // party becomes a managed file of this installation.
        return false;
    }

    public function requiresAdmin(): bool
    {
        // The technical user of an A2A client is never an administrator
        // (A2A-SEC3); its own file mounts decide.
        return false;
    }

    public function getGroup(): string
    {
        return 'files';
    }

    /**
     * Everything the write needs, or why it cannot happen.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{row: array<string, mixed>, owner: int, folder: Folder, name: string, target: string}|string
     */
    private function plan(array $arguments, ToolExecutionContext $context): array|string
    {
        $run  = $context->run;
        $task = $run instanceof AgentRunReference ? $this->taskOfRun($run) : null;
        if ($task === null) {
            return self::NO_TASK;
        }

        if (!$this->fileSettings->enabled()) {
            return self::FILES_OFF;
        }

        $profile = $task['profile'];
        if ($profile->falInboxFolder === '') {
            return self::NO_INBOX;
        }

        $refusal = $this->inbox->refusal($profile->falInboxFolder);
        $target  = A2aFalInbox::parse($profile->falInboxFolder);
        if ($refusal !== null || $target === null) {
            return 'Not adopted: the inbox folder of this skill cannot be written to: ' . ($refusal ?? 'it is not a combined folder identifier.');
        }

        $number = self::toInt($arguments['file'] ?? 0);
        $row    = $this->clientFile($task, $number);
        if ($row === null) {
            return sprintf(self::NO_FILE, $number);
        }

        $folder = $this->storageGate->folderToAddFilesTo($context->actingBackendUser(), $target['storage'], $target['folder']);
        if (!$folder instanceof Folder) {
            return self::NOT_WRITABLE;
        }

        $wanted = $this->targetName(self::toStr($row['filename'] ?? ''), self::toStr($row['media_type'] ?? ''));
        try {
            $name = $this->freeName($folder, $wanted);
        } catch (Throwable) {
            return self::NOT_WRITABLE;
        }

        if ($name === null) {
            return sprintf(self::NO_FREE_NAME, $wanted);
        }

        return ['row' => $row, 'owner' => $task['owner'], 'folder' => $folder, 'name' => $name, 'target' => $target['storage'] . ':' . $target['folder']];
    }

    /**
     * The name the file is stored as: the first free one of
     * {@see self::candidates()}. Null when all are taken.
     */
    private function freeName(Folder $folder, string $wanted): ?string
    {
        foreach ($this->candidates($folder, $wanted) as $candidate) {
            if (!$this->taken($folder, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The names the file may be stored as, in order: sanitised as core does,
     * then with core's `_01` to `_99` suffix.
     *
     * @return list<string>
     */
    private function candidates(Folder $folder, string $wanted): array
    {
        $name       = $folder->getStorage()->sanitizeFileName($wanted, $folder);
        $info       = PathUtility::pathinfo($name);
        $stem       = (string) preg_replace('/_[0-9][0-9]$/', '', $info['filename'] ?? '');
        $extension  = ($info['extension'] ?? '') !== '' ? '.' . $info['extension'] : '';
        $candidates = [$name];
        for ($number = 1; $number <= 99; ++$number) {
            $candidates[] = sprintf('%s_%02d%s', $stem, $number, $extension);
        }

        return $candidates;
    }

    private function taken(Folder $folder, string $name): bool
    {
        return $folder->hasFile($name) || $folder->hasFolder($name);
    }

    /**
     * The stored name on the card the approver saw: the candidate whose name
     * line, rendered as the preview renders it for the same acting user, is
     * among the approved lines. Null without an approved card, or when none
     * matches.
     *
     * @param array<string, mixed>                                                                       $arguments
     * @param array{row: array<string, mixed>, owner: int, folder: Folder, name: string, target: string} $plan
     */
    private function approvedName(array $arguments, ToolExecutionContext $context, array $plan): ?string
    {
        if ($context->approvedPreview === null) {
            return null;
        }

        $approved = array_map($this->normalised(...), $context->approvedPreview);
        $user     = $context->actingBackendUser();
        $wanted   = $this->targetName(self::toStr($plan['row']['filename'] ?? ''), self::toStr($plan['row']['media_type'] ?? ''));
        foreach ($this->candidates($plan['folder'], $wanted) as $candidate) {
            if (in_array($this->normalised($this->nameLine($user, $arguments, $plan['row'], $candidate)), $approved, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The preview's name line for one stored name.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $row
     */
    private function nameLine(?BackendUserAuthentication $user, array $arguments, array $row, string $name): string
    {
        return $this->translator->text(
            $user,
            ApprovalPreviewLabel::AdoptClientFileName,
            self::toInt($arguments['file'] ?? 0),
            $this->withoutFormatCharacters(self::toStr($row['filename'] ?? '')),
            $name,
        );
    }

    /**
     * A preview line as the run state keeps it (ApprovalPreviewComparator::bound()):
     * whitespace collapsed, at most 500 characters.
     */
    private function normalised(string $line): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $line)), 0, 500);
    }

    /**
     * Whether a file of this name is in the folder now.
     */
    private function written(Folder $folder, string $name): bool
    {
        try {
            return $folder->hasFile($name);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * The name without Unicode format characters (category Cf), such as a
     * right-to-left override that would show it reversed.
     */
    private function withoutFormatCharacters(string $name): string
    {
        return (string) preg_replace('/\p{Cf}/u', '', $name);
    }

    /**
     * The checked file N of the task while its bytes are held. N counts as
     * the run was told: in the order of the task's manifest, which
     * {@see A2aFileInputPreparer} numbers the files by.
     *
     * @param array{uid: int, owner: int, manifest: string, profile: A2aSkillProfile} $task
     *
     * @return array<string, mixed>|null
     */
    private function clientFile(array $task, int $number): ?array
    {
        $entry = $number > 0 ? ($this->fileStore->manifest($task['manifest'])[$number - 1] ?? null) : null;
        $row   = is_array($entry) ? ($this->fileStore->rowsOfTask($task['uid'], $task['owner'])[$entry['fileId']] ?? null) : null;
        $owner = $task['owner'];
        if (!is_array($row)
            || ($row['check_state'] ?? null) !== A2aFileStore::CHECK_PASSED
            || ($row['approval_state'] ?? null) !== A2aFileStore::APPROVAL_APPROVED
            || !isset(self::EXTENSIONS[self::toStr($row['media_type'] ?? '')])) {
            return null;
        }

        // The task ended: its input files gave up their bytes (A2A-FILE4).
        return $this->fileStore->content(self::toInt($row['uid'] ?? 0), $owner) !== '' ? $row : null;
    }

    /**
     * The client's name without any path, with an extension of the file's
     * checked type; core sanitises the rest.
     */
    private function targetName(string $clientName, string $mediaType): string
    {
        $extensions = self::EXTENSIONS[$mediaType] ?? ['bin'];
        $base       = basename(str_replace('\\', '/', $this->withoutFormatCharacters($clientName)));
        $extension  = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if (in_array($extension, $extensions, true)) {
            return $base;
        }

        $stem = pathinfo($base, PATHINFO_FILENAME);

        return ($stem !== '' && $stem !== '.' ? $stem : 'client-file') . '.' . $extensions[0];
    }

    /**
     * The task the run executes, with its owner and its profile read now:
     * a profile changed while the run waits counts at the next call.
     *
     * @return array{uid: int, owner: int, manifest: string, profile: A2aSkillProfile}|null
     */
    private function taskOfRun(AgentRunReference $run): ?array
    {
        if ($run->uid < 1) {
            return null;
        }

        $task = $this->connectionPool->getConnectionForTable(A2aTaskStore::TABLE)
            ->select(['uid', 'owner_client_uid', 'profile_uid', 'input_manifest'], A2aTaskStore::TABLE, ['run_uid' => $run->uid])
            ->fetchAssociative();
        if (!is_array($task) || self::toInt($task['owner_client_uid'] ?? 0) < 1) {
            return null;
        }

        $profile = $this->profiles->findByUid(self::toInt($task['profile_uid'] ?? 0));

        return $profile instanceof A2aSkillProfile
            ? ['uid' => self::toInt($task['uid'] ?? 0), 'owner' => self::toInt($task['owner_client_uid'] ?? 0), 'manifest' => self::toStr($task['input_manifest'] ?? ''), 'profile' => $profile]
            : null;
    }
}
