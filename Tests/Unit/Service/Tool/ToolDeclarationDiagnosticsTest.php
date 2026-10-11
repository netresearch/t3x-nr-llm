<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Error;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\EditorActionInterface;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityService;
use Netresearch\NrLlm\Service\Tool\ToolGroupStateRepository;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\EditorActionDiagnosticLogger;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeEditorActionTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(ToolAvailabilityService::class)]
final class ToolDeclarationDiagnosticsTest extends TestCase
{
    #[Test]
    #[DataProvider('diagnosticAndDeclarationFailures')]
    public function malformedDeclarationKeepsItsCauseAndHealthySibling(
        string $mode,
        bool $engineError,
    ): void {
        $cause = $engineError ? new Error('Foreign declaration failed.', 1770581116) : new RuntimeException('Foreign declaration failed.', 1770581116);
        $diagnosticFailure = match ($mode) {
            'runtime' => new RuntimeException('Optional diagnostic unavailable.'),
            'error' => new Error('Optional diagnostic unavailable.'),
            default => null,
        };
        $logger = $mode === 'none' ? null : new EditorActionDiagnosticLogger($diagnosticFailure);
        $broken = $this->createMockForIntersectionOfInterfaces(
            [ToolInterface::class, EditorActionInterface::class],
        );
        $broken
            ->method('getSpec')
            ->willReturn(
                ToolSpec::function(
                    'broken',
                    'Broken declaration',
                    ['type' => 'object', 'properties' => []],
                ),
            );
        $broken->method('getEditorAction')->willThrowException($cause);
        $healthy = new FakeEditorActionTool('healthy');
        $pool = self::createStub(ConnectionPool::class);
        $service = new ToolAvailabilityService(
            new ToolRegistry([$broken, $healthy]),
            new ToolStateRepository($pool),
            new ToolGroupStateRepository($pool),
            $logger,
        );
        $actions = null;
        $escaped = null;
        try {
            $actions = $service->editorActions();
        } catch (Throwable $exception) {
            $escaped = $exception;
        }

        self::assertNull(
            $escaped,
            'Optional diagnostics must preserve the per-tool presentation fallback.',
        );
        self::assertSame(['healthy' => $healthy->getEditorAction()], $actions);
        if ($logger instanceof EditorActionDiagnosticLogger) {
            self::assertSame(
                [
                    [
                        'level' => 'warning',
                        'message' => 'Tool {tool} declares a malformed editor action; rendering it as an undeclared tool.',
                        'context' => ['tool' => 'broken', 'exception' => $cause],
                    ],
                ],
                $logger->records,
            );
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function diagnosticAndDeclarationFailures(): iterable
    {
        foreach (['runtime', 'error', 'recording', 'none'] as $mode) {
            foreach ([false, true] as $engineError) {
                yield $mode . ($engineError ? '-declaration-error' : '-declaration-runtime') => [$mode, $engineError];
            }
        }
    }

    #[Test]
    public function healthyDeclarationsDoNotEmitWarnings(): void
    {
        $healthy = new FakeEditorActionTool('healthy');
        $logger = new EditorActionDiagnosticLogger(
            new Error('A healthy declaration must never log.'),
        );
        $pool = self::createStub(ConnectionPool::class);
        $service = new ToolAvailabilityService(
            new ToolRegistry([$healthy]),
            new ToolStateRepository($pool),
            new ToolGroupStateRepository($pool),
            $logger,
        );
        self::assertSame(
            ['healthy' => $healthy->getEditorAction()],
            $service->editorActions(),
        );
        self::assertSame([], $logger->records);
    }

    #[Test]
    #[DataProvider('stateFailures')]
    public function authoritativeStateReadFailuresStillPropagate(
        bool $engineError,
    ): void {
        $failure = $engineError ? new Error('State storage unavailable.') : new RuntimeException('State storage unavailable.');
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getQueryBuilderForTable')->willThrowException($failure);
        $logger = new EditorActionDiagnosticLogger();
        $service = new ToolAvailabilityService(
            new ToolRegistry(),
            new ToolStateRepository($pool),
            new ToolGroupStateRepository($pool),
            $logger,
        );
        $escaped = null;
        try {
            $service->states();
        } catch (Throwable $exception) {
            $escaped = $exception;
        }

        self::assertSame($failure, $escaped);
        self::assertSame([], $logger->records);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function stateFailures(): iterable
    {
        yield 'runtime' => [false];
        yield 'error' => [true];
    }
}
