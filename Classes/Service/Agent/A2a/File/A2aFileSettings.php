<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\File;

use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * What the installation says about A2A file parts (spec 051 stage 5c,
 * ADR-284): the switch, the size limit and the expiry of stored files.
 *
 * The switch `a2a.files.enabled` is off by default (A2A-OPS2). An unreadable
 * value is a switch that is off and the smallest limit, never a wider one.
 * The size limit is `a2a.maxRawPartBytes` (A2A-FILE2), one value for a part
 * and for all files of one message together; `a2a.maxInlineArtifactBytes`
 * bounds what a task sends inline (A2A-FILE5).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aFileSettings
{
    public const RAW_PART_BYTES_DEFAULT = 5242880;

    public const RAW_PART_BYTES_MIN = 1024;

    /**
     * Sealed, a file takes 1.81 times its size in its row (measured at
     * 5 MiB); 8 MiB stays below the 16 MiB a MariaDB packet holds by default.
     */
    public const RAW_PART_BYTES_MAX = 8388608;

    public const RETENTION_HOURS_DEFAULT = 168;

    public const INLINE_ARTIFACT_BYTES_DEFAULT = 262144;

    /**
     * Base64 makes 4/3 of it, so the inline files of a task still fit into
     * one event of a stream at the default `a2a.server.maxFrameBytes` (1 MiB).
     */
    public const INLINE_ARTIFACT_BYTES_MAX = 524288;

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function enabled(): bool
    {
        return in_array($this->files('enabled'), [true, 1, '1'], true);
    }

    /**
     * The decoded bytes a file part, and all file parts of one message
     * together, may hold.
     */
    public function maxRawPartBytes(): int
    {
        return $this->bounded($this->a2a('maxRawPartBytes'), self::RAW_PART_BYTES_DEFAULT, self::RAW_PART_BYTES_MIN, self::RAW_PART_BYTES_MAX);
    }

    /**
     * The length of the base64 text of {@see self::maxRawPartBytes()} bytes,
     * padding included: the limit on the text before it is decoded.
     */
    public function maxBase64Length(): int
    {
        return 4 * intdiv($this->maxRawPartBytes() + 2, 3);
    }

    /**
     * The bytes the files a task sends may hold together inline, as `raw`
     * parts of its artifacts (A2A-FILE5); a file that does not fit goes out
     * as a download. 0 sends every file as a download.
     */
    public function maxInlineArtifactBytes(): int
    {
        return $this->bounded($this->a2a('maxInlineArtifactBytes'), self::INLINE_ARTIFACT_BYTES_DEFAULT, 0, self::INLINE_ARTIFACT_BYTES_MAX);
    }

    /**
     * Hours a stored file is kept at most, 1 to 8760.
     */
    public function retentionHours(): int
    {
        return $this->bounded($this->files('retentionHours'), self::RETENTION_HOURS_DEFAULT, 1, 8760);
    }

    private function bounded(mixed $value, int $default, int $min, int $max): int
    {
        $int = is_numeric($value) ? (int) $value : $default;

        return max($min, min($max, $int));
    }

    private function files(string $key): mixed
    {
        $node = $this->a2a('files');

        return is_array($node) ? ($node[$key] ?? null) : null;
    }

    private function a2a(string $key): mixed
    {
        try {
            $configuration = $this->extensionConfiguration->get('nr_llm');
        } catch (Throwable) {
            return null;
        }

        $node = is_array($configuration) ? ($configuration['a2a'] ?? null) : null;

        return is_array($node) ? ($node[$key] ?? null) : null;
    }
}
