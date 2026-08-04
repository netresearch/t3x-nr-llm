<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Service\Tool\Builtin\CheckTypoScriptTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetRecordHistoryTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ResolveUrlTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ValidateTcaTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Spec shape + admin/default flags of the history/URL/validation tools that
 * don't need a TYPO3 bootstrap (ADR-046). The DB/routing/TS paths are
 * covered functionally. getSpec()/requiresAdmin()/isEnabledByDefault() touch
 * no collaborator, so newInstanceWithoutConstructor is safe.
 */
#[CoversClass(CheckTypoScriptTool::class)]
#[CoversClass(GetRecordHistoryTool::class)]
#[CoversClass(ResolveUrlTool::class)]
#[CoversClass(ValidateTcaTool::class)]
final class HistoryValidationToolsSpecTest extends TestCase
{
    /**
     * @param class-string<ToolInterface> $class
     */
    private function bare(string $class): ToolInterface
    {
        $tool = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(ToolInterface::class, $tool);

        return $tool;
    }

    #[Test]
    public function specsExposeNamesAndRequiredParameters(): void
    {
        $history = $this->bare(GetRecordHistoryTool::class)->getSpec();
        self::assertSame('get_record_history', $history->name);
        self::assertSame(['table', 'uid'], $history->parameters['required'] ?? []);

        $resolve = $this->bare(ResolveUrlTool::class)->getSpec();
        self::assertSame('resolve_url', $resolve->name);
        self::assertSame(['url'], $resolve->parameters['required'] ?? []);

        $validate = $this->bare(ValidateTcaTool::class)->getSpec();
        self::assertSame('validate_tca', $validate->name);
        self::assertArrayNotHasKey('required', $validate->parameters);

        $check = $this->bare(CheckTypoScriptTool::class)->getSpec();
        self::assertSame('check_typoscript', $check->name);
        self::assertSame(['pageUid'], $check->parameters['required'] ?? []);
    }

    #[Test]
    public function adminAndDefaultFlagsArePinned(): void
    {
        self::assertFalse($this->bare(GetRecordHistoryTool::class)->requiresAdmin());
        self::assertFalse($this->bare(ResolveUrlTool::class)->requiresAdmin());
        self::assertFalse($this->bare(ValidateTcaTool::class)->requiresAdmin());
        // check_typoscript scans configuration — admin-only like get_typoscript.
        self::assertTrue($this->bare(CheckTypoScriptTool::class)->requiresAdmin());

        self::assertTrue($this->bare(GetRecordHistoryTool::class)->isEnabledByDefault());
        self::assertTrue($this->bare(ResolveUrlTool::class)->isEnabledByDefault());
        self::assertTrue($this->bare(ValidateTcaTool::class)->isEnabledByDefault());
        self::assertTrue($this->bare(CheckTypoScriptTool::class)->isEnabledByDefault());
    }

    #[Test]
    public function invalidInputIsRejectedWithoutTouchingCollaborators(): void
    {
        // No $GLOBALS['BE_USER'] in unit context → fail-closed paths.
        self::assertSame(
            'Table not found or not permitted.',
            $this->bare(GetRecordHistoryTool::class)->execute(['table' => '', 'uid' => 0], ToolExecutionContext::none())->content,
        );
        self::assertSame(
            'Page not found or no TypoScript template.',
            $this->bare(CheckTypoScriptTool::class)->execute(['pageUid' => 0], ToolExecutionContext::none())->content,
        );
        self::assertSame(
            'Error: "url" is required.',
            $this->bare(ResolveUrlTool::class)->execute([], ToolExecutionContext::none())->content,
        );
        self::assertSame(
            'Page not found or not permitted.',
            $this->bare(ResolveUrlTool::class)->execute(['url' => '/x'], ToolExecutionContext::none())->content,
        );
    }
}
