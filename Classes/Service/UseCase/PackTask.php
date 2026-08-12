<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\UseCase;

use Netresearch\NrLlm\Domain\Enum\TaskCategory;
use Netresearch\NrLlm\Domain\Enum\TaskInputType;
use Netresearch\NrLlm\Domain\Enum\TaskOutputFormat;
use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * One `tx_nrllm_task` record a pack declares (ADR-163).
 *
 * The installed record is an ORDINARY task: same table, same TCA, same
 * execution path, editable and deletable like any other. Nothing here marks it
 * as pack-owned at runtime, because a task that behaved differently for having
 * come from a pack would be a second task system.
 *
 * The bounds mirror the TCA contract of `tx_nrllm_task` (identifier
 * `alphanum_x,lower` max 100, name max 255) and are checked at declaration
 * time: the installer writes through Extbase, not FormEngine, so an overlong
 * declared value would reach a strict-mode DBMS unvalidated.
 */
final readonly class PackTask
{
    /** `alphanum_x,lower` — lowercase letters, digits, `_` and `-`. No dots. */
    private const IDENTIFIER_PATTERN = '/^[a-z0-9_-]+$/';

    /** The `tx_nrllm_task.identifier` column is varchar(100). */
    private const IDENTIFIER_MAX_LENGTH = 100;

    /** The `tx_nrllm_task.name` column is varchar(255). */
    private const NAME_MAX_LENGTH = 255;

    public function __construct(
        public string $identifier,
        public string $name,
        public string $description,
        public string $promptTemplate,
        public TaskCategory $category = TaskCategory::CONTENT,
        public TaskInputType $inputType = TaskInputType::MANUAL,
        public TaskOutputFormat $outputFormat = TaskOutputFormat::MARKDOWN,
    ) {
        if (preg_match(self::IDENTIFIER_PATTERN, $identifier) !== 1) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid pack task identifier "%s": expected lowercase [a-z0-9_-], matching the tx_nrllm_task TCA contract.',
                    $identifier,
                ),
                1791460001,
            );
        }

        if (strlen($identifier) > self::IDENTIFIER_MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf(
                    'Pack task identifier "%s" exceeds the %d-character identifier column limit.',
                    $identifier,
                    self::IDENTIFIER_MAX_LENGTH,
                ),
                1791460002,
            );
        }

        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf(
                    'Pack task "%s" name must be 1-%d characters (name column limit); got %d.',
                    $identifier,
                    self::NAME_MAX_LENGTH,
                    mb_strlen($name),
                ),
                1791460003,
            );
        }

        if (trim($promptTemplate) === '') {
            throw new InvalidArgumentException(
                sprintf('Pack task "%s" declares an empty prompt template.', $identifier),
                1791460004,
            );
        }
    }
}
