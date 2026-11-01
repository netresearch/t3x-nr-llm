<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool\Vision\Fixtures;

use RuntimeException;
use TYPO3\CMS\Core\Resource\Processing\ProcessorInterface;
use TYPO3\CMS\Core\Resource\Processing\TaskInterface;

/**
 * Scales with GD, because a CI runner has no ImageMagick and the test is about
 * the route to a processor, not about the processor. It sits behind core's
 * deferred processor, so a request that stays deferred never reaches it.
 */
final class GdImageProcessor implements ProcessorInterface
{
    public function canProcessTask(TaskInterface $task): bool
    {
        return $task->getName() === 'CropScaleMask';
    }

    public function processTask(TaskInterface $task): void
    {
        $source = imagecreatefrompng($task->getSourceFile()->getForLocalProcessing(false));
        if ($source === false) {
            throw new RuntimeException('Not a PNG', 1798000104);
        }

        $width  = $task->getConfiguration()['maxWidth'] ?? 0;
        $scaled = imagescale($source, is_numeric($width) ? (int) $width : 0);
        if ($scaled === false) {
            throw new RuntimeException('Not scaled', 1798000105);
        }

        $path = tempnam(sys_get_temp_dir(), 'nrllm') . '.png';
        imagepng($scaled, $path);

        $target = $task->getTargetFile();
        $target->setName($task->getTargetFileName());
        $target->updateProperties([
            'width'    => imagesx($scaled),
            'height'   => imagesy($scaled),
            'size'     => filesize($path),
            'checksum' => $task->getConfigurationChecksum(),
        ]);
        $target->updateWithLocalFile($path);

        $task->setExecuted(true);
    }
}
