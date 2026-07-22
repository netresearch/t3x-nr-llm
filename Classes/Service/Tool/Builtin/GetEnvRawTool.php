<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolDataClassInterface;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;

/**
 * Return ALL process environment variables, unredacted.
 *
 * Security contract (see {@see ToolInterface}): this egresses raw secret
 * values — the database password, the TYPO3 encryptionKey, the nr-vault master
 * key, provider API keys and any token in the environment — to the configured
 * LLM provider and into the rendered backend DOM. There is no redaction; the
 * only guard is that this tool is admin-only AND
 * {@see isEnabledByDefault()} = false, so an admin must deliberately enable it
 * in the Tool Playground module before it can ever run. Prefer the redacted
 * {@see GetEnvTool} unless raw values are genuinely required.
 */
final readonly class GetEnvRawTool implements ToolInterface, ToolDataClassInterface
{
    use CollectsEnvironmentTrait;
    use SafeCastTrait;

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'get_env_raw',
            'Return ALL process environment variables WITHOUT redaction (including secrets such as the '
            . 'database password and encryption keys). Disabled by default — admin must enable it.',
            [
                'type'       => 'object',
                'properties' => [],
            ],
        );
    }

    public function execute(array $arguments): ToolResult
    {
        $env = $this->collectEnvironment();
        if ($env === []) {
            return ToolResult::text('No environment variables.');
        }

        ksort($env);
        $lines = [];
        foreach ($env as $name => $value) {
            $lines[] = $name . '=' . $value;
        }

        return ToolResult::text(implode("\n", $lines));
    }

    public function isEnabledByDefault(): bool
    {
        return false;
    }

    public function requiresAdmin(): bool
    {
        // Admin-only: exposes system / host / cross-user data a non-admin must never reach.
        return true;
    }

    public function getGroup(): string
    {
        return 'system';
    }

    /**
     * Environment variables routinely carry database passwords, API keys and vault credentials.
     */
    public function getDataClass(): ToolDataClass
    {
        return ToolDataClass::SECRET_ADJACENT;
    }
}
