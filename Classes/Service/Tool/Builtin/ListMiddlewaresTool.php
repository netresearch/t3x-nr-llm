<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use Throwable;
use TYPO3\CMS\Core\Http\MiddlewareStackResolver;

/**
 * List a PSR-15 middleware stack in execution order (ADR-048).
 *
 * Middleware identifier plus implementing class per entry — the map for
 * "which middleware could intercept/redirect this request".
 *
 * Security contract (see {@see ToolInterface}): admin-only. The resolver is
 * `@internal` core API (stable across 13.4/14 except its return type,
 * array vs ArrayObject — both iterable, handled transparently); any
 * resolution failure collapses into one neutral message. Output is capped.
 */
final readonly class ListMiddlewaresTool implements ToolInterface
{
    use SafeCastTrait;

    private const STACKS = ['frontend', 'backend'];

    private const MAX_ENTRIES = 100;

    public function __construct(
        private MiddlewareStackResolver $stackResolver,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'list_middlewares',
            'List a PSR-15 middleware stack ("frontend" or "backend") in execution order: '
            . 'middleware identifier and implementing class.',
            [
                'type'       => 'object',
                'properties' => [
                    'stack' => [
                        'type'        => 'string',
                        'enum'        => self::STACKS,
                        'description' => 'The middleware stack to list (default "frontend").',
                    ],
                ],
            ],
        );
    }

    public function execute(array $arguments): string
    {
        $stack = trim(self::toStr($arguments['stack'] ?? 'frontend'));
        if (!in_array($stack, self::STACKS, true)) {
            return sprintf('Unknown stack — use one of: %s.', implode(', ', self::STACKS));
        }

        try {
            // array on 13.4, ArrayObject on v14 — both iterate identically.
            $middlewares = $this->stackResolver->resolve($stack);
        } catch (Throwable) {
            return 'Could not resolve the middleware stack.';
        }

        $lines = [];
        foreach ($middlewares as $identifier => $className) {
            if (count($lines) >= self::MAX_ENTRIES) {
                $lines[] = '… more entries not shown';
                break;
            }
            $lines[] = sprintf('%2d. %s (%s)', count($lines) + 1, (string)$identifier, self::toStr($className));
        }

        if ($lines === []) {
            return sprintf('The %s middleware stack is empty.', $stack);
        }

        return sprintf("PSR-15 %s middleware stack (%d, execution order):\n", $stack, count($lines))
            . implode("\n", $lines);
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Admin-only: the middleware map is request-processing internals.
        return true;
    }

    public function getGroup(): string
    {
        return 'system';
    }
}
