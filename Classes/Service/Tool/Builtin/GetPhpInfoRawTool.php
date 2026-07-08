<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolInterface;

/**
 * Return the full phpinfo(INFO_ALL) output as captured plain text.
 *
 * Security contract (see {@see ToolInterface}): phpinfo dumps the complete
 * runtime — including `$_SERVER`, `$_ENV`, loaded ini paths and per-extension
 * configuration — which routinely carries secrets (database credentials in
 * server vars, API keys in the environment, absolute filesystem paths). The
 * whole capture egresses to the LLM provider and into the rendered DOM. The
 * only guard is that this tool is admin-only AND
 * {@see isEnabledByDefault()} = false, so an admin must deliberately enable it
 * in the Tool Playground module. Prefer the curated {@see GetPhpInfoTool}.
 */
final readonly class GetPhpInfoRawTool implements ToolInterface
{
    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'get_php_info_raw',
            'Return the full phpinfo(INFO_ALL) output as plain text (includes $_SERVER, $_ENV and other '
            . 'secret-bearing runtime detail). Disabled by default — admin must enable it.',
            [
                'type'       => 'object',
                'properties' => [],
            ],
        );
    }

    public function execute(array $arguments): string
    {
        ob_start();
        phpinfo(INFO_ALL);
        $info = ob_get_clean();

        if (!is_string($info) || $info === '') {
            return 'phpinfo unavailable.';
        }

        // In a web SAPI (the TYPO3 backend) phpinfo() emits HTML, not the CLI's
        // plain text. Returning raw HTML to the provider wastes a large amount of
        // tokens and can blow the context window, so reduce it to text: drop the
        // <head>/<style>, strip tags, decode entities and collapse blank runs.
        if (stripos($info, '<html') !== false || stripos($info, '<table') !== false) {
            $info = (string)preg_replace('#<(head|style|script)\b[^>]*>.*?</\1>#is', '', $info);
            $info = strip_tags($info);
            $info = html_entity_decode($info, ENT_QUOTES | ENT_HTML5);
            $info = (string)preg_replace("/\n{3,}/", "\n\n", $info);
            $info = trim($info);
        }

        return $info !== '' ? $info : 'phpinfo unavailable.';
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
}
