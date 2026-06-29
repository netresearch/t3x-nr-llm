<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

/**
 * Shared environment-variable collection for the env tools.
 *
 * Both {@see GetEnvTool} (redacted) and {@see GetEnvRawTool} (unredacted) build
 * the same name => value map from getenv() and $_ENV; only their rendering of
 * the values differs. The collection lives here so the two tools do not carry
 * an identical copy.
 *
 * The consuming class must provide `self::toStr()` (via
 * {@see \Netresearch\NrLlm\Utility\SafeCastTrait}).
 */
trait CollectsEnvironmentTrait
{
    /**
     * Merge getenv() and $_ENV into a single name => value map of strings.
     *
     * @return array<string, string>
     */
    private function collectEnvironment(): array
    {
        $env = [];

        $all = getenv();
        if (is_array($all)) {
            foreach ($all as $name => $value) {
                $env[self::toStr($name)] = self::toStr($value);
            }
        }

        /** @var mixed $value */
        foreach ($_ENV as $name => $value) {
            if (is_scalar($value)) {
                $env[self::toStr($name)] = self::toStr($value);
            }
        }

        unset($env['']);

        return $env;
    }
}
