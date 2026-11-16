<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\File;

use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Symfony\Component\Uid\Uuid;

/**
 * The artifacts of the files a task sends (spec 051 A2A-FILE5, ADR-284),
 * built when its run completes, in the completion's transaction.
 *
 * - Files go inline as `raw` while they fit together into
 *   `a2a.maxInlineArtifactBytes`; every further file goes out as a `url` to
 *   the protected download below the endpoint the task was accepted at
 *   (`<endpoint>/files/<file id>`), never to the installation's own storage.
 *   The URL carries no secret: the client's bearer token reads it, or a
 *   capability URL the client asks for ({@see A2aFileCapability}).
 * - Each file is one artifact with its own id; its part names the file's id,
 *   SHA-256 and size under {@see A2aInboundFilePolicy::FILE_EXTENSION}.
 * - A file is content that cannot be redacted: below the `full` answer
 *   level no file is sent, and one text artifact says how many were
 *   withheld.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aOutboundFileArtifacts
{
    public const DOWNLOAD_PATH = '/files/';

    public function __construct(
        private A2aFileStore $store,
        private A2aFileSettings $settings,
    ) {}

    /**
     * @return list<array<string, mixed>> A2A artifacts; empty when the task sends no file
     */
    public function artifactsFor(int $taskUid, int $ownerClientUid, string $endpoint, PrivacyLevel $ceiling): array
    {
        $rows = $this->store->outboundRowsOfTask($taskUid, $ownerClientUid);
        if ($rows === []) {
            return [];
        }

        if ($ceiling !== PrivacyLevel::FULL) {
            return [[
                'artifactId' => Uuid::v7()->toRfc4122(),
                'name'       => 'files',
                'parts'      => [['text' => sprintf('%d %s withheld: the privacy policy of this installation keeps no file content for this task.', count($rows), count($rows) === 1 ? 'file was' : 'files were')]],
            ]];
        }

        $budget    = $this->settings->maxInlineArtifactBytes();
        $artifacts = [];
        foreach ($rows as $row) {
            $fileId   = is_string($row['file_id'] ?? null) ? $row['file_id'] : '';
            $size     = is_numeric($row['size'] ?? null) ? (int) $row['size'] : 0;
            $sha256   = is_string($row['sha256'] ?? null) ? $row['sha256'] : '';
            $filename = is_string($row['filename'] ?? null) ? $row['filename'] : '';
            $part     = [
                'mediaType' => is_string($row['media_type'] ?? null) ? $row['media_type'] : '',
                'metadata'  => [A2aInboundFilePolicy::FILE_EXTENSION => ['fileId' => $fileId, 'sha256' => $sha256, 'size' => $size]],
            ];
            if ($filename !== '') {
                $part['filename'] = $filename;
            }

            $bytes = $size <= $budget ? $this->store->content(is_numeric($row['uid'] ?? null) ? (int) $row['uid'] : 0, $ownerClientUid) : null;
            if ($bytes !== null && hash_equals($sha256, hash('sha256', $bytes))) {
                $budget -= $size;
                $part = ['raw' => base64_encode($bytes)] + $part;
            } elseif ($endpoint !== '') {
                $part = ['url' => rtrim($endpoint, '/') . self::DOWNLOAD_PATH . $fileId] + $part;
            } else {
                // A task accepted before the endpoint was recorded has no
                // address to download from; a relative URL would be none.
                $part = ['text' => sprintf('The file %s is too large to send inline, and this task has no download address.', $filename !== '' ? $filename : $fileId)];
            }

            $artifacts[] = ['artifactId' => Uuid::v7()->toRfc4122(), 'name' => $filename !== '' ? $filename : 'file', 'parts' => [$part]];
        }

        return $artifacts;
    }
}
