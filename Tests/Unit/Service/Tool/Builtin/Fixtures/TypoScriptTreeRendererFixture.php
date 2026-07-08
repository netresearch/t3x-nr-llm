<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin\Fixtures;

use Netresearch\NrLlm\Service\Tool\Builtin\RendersTypoScriptTreeTrait;

/**
 * Exposes the trait's private helpers for direct unit testing.
 */
final class TypoScriptTreeRendererFixture
{
    use RendersTypoScriptTreeTrait;

    /**
     * @param array<array-key, mixed> $tree
     *
     * @return array{0: string|null, 1: array<array-key, mixed>|null}
     */
    public function drill(array $tree, string $path): array
    {
        return $this->drillPath($tree, $path);
    }

    /**
     * @param array<array-key, mixed> $tree
     *
     * @return list<string>
     */
    public function render(array $tree): array
    {
        $lines = [];
        $this->renderTree($tree, $lines);

        return $lines;
    }

    /**
     * @param array<array-key, mixed> $tree
     *
     * @return list<string>
     */
    public function topLevel(array $tree): array
    {
        return $this->renderTopLevelKeys($tree);
    }

    public function redact(string $key, string $value): string
    {
        return $this->redactSecretValue($key, $value);
    }
}
