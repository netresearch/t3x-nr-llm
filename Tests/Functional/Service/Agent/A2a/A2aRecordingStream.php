<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a;

use RuntimeException;
use TYPO3\CMS\Core\Http\Stream;

/**
 * A request body that counts what was read from it.
 */
final class A2aRecordingStream extends Stream
{
    public int $bytesRead = 0;

    public function __construct(string $content)
    {
        parent::__construct('php://temp', 'rw');
        parent::write($content);
        parent::rewind();
    }

    public function read(int $length): string
    {
        $chunk = parent::read($length);
        $this->bytesRead += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        $contents = parent::getContents();
        $this->bytesRead += strlen($contents);

        return $contents;
    }

    public function __toString(): string
    {
        throw new RuntimeException('The receiver must read the body in bounded chunks', 1799300001);
    }
}
