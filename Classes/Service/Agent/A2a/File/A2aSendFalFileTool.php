<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\File;

use Netresearch\NrLlm\Domain\ValueObject\AgentRunReference;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aException;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSkillProfile;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSkillProfileRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Tool\Builtin\DeclaresReadEffectTrait;
use Netresearch\NrLlm\Service\Tool\Builtin\FetchesSysFileRowTrait;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * `send_fal_file`: a run in the A2A server role puts ONE managed file into its
 * task's result (spec 051 A2A-FILE6, ADR-284).
 *
 * The file goes to the client only when both hold, asked anew at every call:
 *
 * - the profile of the task approves its clients as recipients of FAL files
 *   (`tx_nrllm_a2a_profile.send_fal_files`);
 * - the run's acting user, which is the client's technical backend user
 *   (A2A-SEC3), reaches the file through its file mounts
 *   ({@see FalStorageGate::isFileAccessible()}).
 *
 * A uid outside those mounts, a uid no file carries and a run without a user
 * get the same answer, so the model cannot probe `sys_file` for existence.
 * Only a file the user may read is then measured and checked: at most
 * `a2a.maxRawPartBytes`, one of the five types, and its content must pass that
 * type's check (A2A-FILE1). The file is registered through
 * {@see A2aFileStore::storeOutboundForRun()} and becomes an artifact when the
 * run completes ({@see A2aOutboundFileArtifacts}); the model is told the
 * file's id, never its content.
 *
 * The tool changes no record of the installation and adds to the task's own
 * result, as the run's answer does; a second call for the same file adds
 * nothing. It declares that it only reads, so a profile without "May write"
 * may offer it: the recipient approval is the switch that governs it.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aSendFalFileTool implements ToolInterface, ToolEffectInterface
{
    use DeclaresReadEffectTrait;
    use FetchesSysFileRowTrait;
    use SafeCastTrait;

    public const NAME = 'send_fal_file';

    /** The origin of a file this tool registers. */
    public const ORIGIN = 'fal';

    /** The sentence every FAL tool denies a file in. */
    private const NOT_PERMITTED = 'Asset not found or not permitted.';

    private const NO_TASK = 'Not sent: this run is no task of the A2A server role, so it has no client to send a file to.';

    private const FILES_OFF = 'Not sent: this installation sends no A2A files.';

    private const NOT_APPROVED = 'Not sent: this skill sends no files from FAL to its clients.';

    public function __construct(
        private ConnectionPool $connectionPool,
        private FalStorageGate $storageGate,
        private A2aFileStore $fileStore,
        private A2aFileSettings $fileSettings,
        private A2aSkillProfileRepository $profiles,
        private ResourceFactory $resourceFactory,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            self::NAME,
            'Send ONE managed file (sys_file), identified by its uid, to the client of this A2A task as a file of '
            . "the task's result. Only a file inside the file mounts of the client's technical user is sent, and "
            . 'only when this skill approves its clients as recipients of FAL files. Types: text/plain, '
            . 'application/json, application/pdf, image/png, image/jpeg. The answer names the file id; the '
            . 'content is not returned to you.',
            [
                'type'       => 'object',
                'properties' => [
                    'uid' => [
                        'type'        => 'integer',
                        'description' => 'The sys_file uid of the file to send.',
                    ],
                ],
                'required' => ['uid'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $run     = $context->run;
        $profile = $run instanceof AgentRunReference ? $this->profileOfRun($run) : null;
        if (!$run instanceof AgentRunReference || !$profile instanceof A2aSkillProfile) {
            return ToolResult::text(self::NO_TASK);
        }

        if (!$this->fileSettings->enabled()) {
            return ToolResult::text(self::FILES_OFF);
        }

        if (!$profile->sendsFalFiles) {
            return ToolResult::text(self::NOT_APPROVED);
        }

        $file = $this->fetchFile(self::toInt($arguments['uid'] ?? 0));

        // One answer for "no user", "no such file" and "not yours": the gate
        // refuses a run without a user as it refuses a file outside the mounts.
        if ($file === null || !$this->storageGate->isFileAccessible(
            $context->actingBackendUser(),
            self::toInt($file['storage'] ?? 0),
            self::toStr($file['identifier'] ?? ''),
        )) {
            return ToolResult::text(self::NOT_PERMITTED);
        }

        return ToolResult::text($this->send($run, self::toInt($file['uid'] ?? 0)));
    }

    public function isEnabledByDefault(): bool
    {
        // Off until an administrator switches it on: a file leaves the
        // installation, and every chat is offered the enabled set.
        return false;
    }

    public function requiresAdmin(): bool
    {
        // The technical user of an A2A client is never an administrator
        // (A2A-SEC3); the storage gate checks its file mounts.
        return false;
    }

    public function getGroup(): string
    {
        return 'files';
    }

    /**
     * The file, measured, checked and registered: what the model reads.
     */
    private function send(AgentRunReference $run, int $fileUid): string
    {
        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
            $name = $file->getName();
            $size = $file->getSize();
            if ($size > $this->fileSettings->maxRawPartBytes()) {
                return sprintf('Not sent: the file %s holds %d bytes, more than the %d an A2A file may hold.', $name, $size, $this->fileSettings->maxRawPartBytes());
            }

            $mediaType = $file->getMimeType();
            if (!in_array($mediaType, A2aFileValidator::MEDIA_TYPES, true)) {
                return sprintf('Not sent: the file %s is of the type %s, and only %s are sent.', $name, $mediaType !== '' ? $mediaType : 'unknown', implode(', ', A2aFileValidator::MEDIA_TYPES));
            }

            $bytes = $file->getContents();
        } catch (Throwable) {
            return 'Not sent: the file could not be read.';
        }

        try {
            $fileId = $this->fileStore->storeOutboundForRun($run->uid, self::ORIGIN, $bytes, $mediaType, $name, time());
        } catch (A2aException $refused) {
            return $refused->getMessage();
        }

        return sprintf('The file %s (%s, %d bytes) goes to the client with the result of this task, as file %s.', $name, $mediaType, strlen($bytes), $fileId);
    }

    /**
     * The profile of the A2A task the run executes, read now: clearing the
     * approval while the run waits takes effect at its next call.
     */
    private function profileOfRun(AgentRunReference $run): ?A2aSkillProfile
    {
        if ($run->uid < 1) {
            return null;
        }

        $profileUid = $this->connectionPool->getConnectionForTable(A2aTaskStore::TABLE)
            ->select(['profile_uid'], A2aTaskStore::TABLE, ['run_uid' => $run->uid])
            ->fetchOne();

        return is_numeric($profileUid) && (int) $profileUid > 0 ? $this->profiles->findByUid((int) $profileUid) : null;
    }
}
