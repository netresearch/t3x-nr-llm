<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\Inbox;

use Closure;
use Error;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunViewFactory;
use Netresearch\NrLlm\Service\Agent\PendingTurnDigest;
use Netresearch\NrLlm\Service\Tool\SchemaPropertyClassifier;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Unit\Language\EnglishPreviewTranslatorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

#[CoversClass(WaitingRunViewFactory::class)]
final class PreviewReadDegradationTest extends TestCase
{
    use EnglishPreviewTranslatorTrait;

    /**
     * @return iterable<string, array{class-string<Throwable>}>
     */
    public static function gateFailures(): iterable
    {
        yield 'runtime exception' => [RuntimeException::class];
        yield 'native error' => [Error::class];
    }

    /**
     * @param class-string<Throwable> $failureClass
     */
    #[DataProvider('gateFailures')]
    public function testFailedReadGateWithholdsOnlyItsOwnPreview(
        string $failureClass,
    ): void {
        $failure = new $failureClass('Private record details must not escape');
        $observedBroken = [];
        $broken = $this->previewTool(
            'broken',
            static function (
                array $arguments,
                BackendUserAuthentication $viewer,
            ) use ($failure, &$observedBroken): bool {
                $observedBroken[] = [$arguments, $viewer];
                throw $failure;
            },
        );
        $observedHealthy = [];
        $healthy = $this->previewTool(
            'healthy',
            static function (
                array $arguments,
                BackendUserAuthentication $viewer,
            ) use (&$observedHealthy): bool {
                $observedHealthy[] = [$arguments, $viewer];
                return true;
            },
        );
        $viewer = self::createStub(BackendUserAuthentication::class);
        $viewer->user = ['uid' => 8];

        $factory = new WaitingRunViewFactory(
            new ToolRegistry([$broken, $healthy]),
            new SchemaPropertyClassifier(),
            new PendingTurnDigest(),
            $this->englishTranslator(),
        );
        $views = [];
        $escaped = null;
        try {
            $views = $factory->buildWaiting(
                [
                    $this->runWithPreview(
                        'first',
                        'broken',
                        ['Sensitive stored preview'],
                    ),
                    $this->runWithPreview(
                        'second',
                        'healthy',
                        ['Healthy stored preview'],
                    ),
                ],
                $viewer,
            );
        } catch (Throwable $caught) {
            $escaped = $caught;
        }

        self::assertNull(
            $escaped,
            'An unresolved preview read gate must not blank the approval inbox.',
        );
        self::assertSame([[['uid' => 42], $viewer]], $observedBroken);
        self::assertSame([[['uid' => 42], $viewer]], $observedHealthy);
        self::assertCount(2, $views);
        self::assertSame('first', $views[0]->runUuid);
        self::assertSame('second', $views[1]->runUuid);
        self::assertSame(WaitingRunView::MODE_APPROVAL, $views[0]->mode);
        self::assertSame(WaitingRunView::MODE_APPROVAL, $views[1]->mode);
        self::assertNotNull($views[0]->turnDigest);
        self::assertNotNull($views[1]->turnDigest);
        self::assertCount(1, $views[0]->pendingCalls);
        self::assertCount(1, $views[1]->pendingCalls);
        self::assertTrue($views[0]->pendingCalls[0]->toolStillRegistered);
        self::assertSame('broken', $views[0]->pendingCalls[0]->name);
        self::assertSame(
            [
                'The preview is not shown: you hold no permission on the record it describes.',
            ],
            $views[0]->pendingCalls[0]->previewLines,
        );
        self::assertTrue($views[0]->pendingCalls[0]->previewFailed);
        self::assertSame(
            ['Healthy stored preview'],
            $views[1]->pendingCalls[0]->previewLines,
        );
        self::assertFalse($views[1]->pendingCalls[0]->previewFailed);
    }

    /**
     * @param class-string<Throwable> $failureClass
     */
    #[DataProvider('gateFailures')]
    public function testRunOwnerKeepsTheCapturedPreviewWithoutRepeatingTheReadGate(
        string $failureClass,
    ): void {
        $failure = new $failureClass('A current record check would fail');
        $calls = 0;
        $tool = $this->previewTool(
            'broken',
            static function () use ($failure, &$calls): bool {
                ++$calls;
                throw $failure;
            },
        );
        $viewer = self::createStub(BackendUserAuthentication::class);
        $viewer->user = ['uid' => 7];

        $factory = new WaitingRunViewFactory(
            new ToolRegistry([$tool]),
            new SchemaPropertyClassifier(),
            new PendingTurnDigest(),
            $this->englishTranslator(),
        );
        $views = $factory->buildWaiting(
            [
                $this->runWithPreview(
                    'owner',
                    'broken',
                    ['Captured owner refusal'],
                    failed: true,
                ),
            ],
            $viewer,
        );

        self::assertSame(
            0,
            $calls,
            'The captured preview was produced under this run owner, including refusals.',
        );
        self::assertCount(1, $views);
        self::assertSame(WaitingRunView::MODE_APPROVAL, $views[0]->mode);
        self::assertSame(
            ['Captured owner refusal'],
            $views[0]->pendingCalls[0]->previewLines,
        );
        self::assertTrue($views[0]->pendingCalls[0]->previewFailed);
    }

    /**
     * A concrete spy preserves the viewer object identity across PHPUnit versions.
     *
     * @param callable(array<string, mixed>, BackendUserAuthentication): bool $gate
     */
    private function previewTool(
        string $name,
        callable $gate,
    ): ToolInterface&ToolPreviewInterface {
        return new class (
            $name,
            Closure::fromCallable($gate),
        ) implements ToolInterface, ToolPreviewInterface {
            /**
             * @param Closure(array<string, mixed>, BackendUserAuthentication): bool $gate
             */
            public function __construct(
                private readonly string $name,
                private readonly Closure $gate,
            ) {}

            public function getSpec(): ToolSpec
            {
                return ToolSpec::function(
                    $this->name,
                    'Preview fixture',
                    ['type' => 'object', 'properties' => []],
                );
            }

            public function mayViewerReadPreview(
                array $arguments,
                BackendUserAuthentication $viewer,
            ): bool {
                return ($this->gate)($arguments, $viewer);
            }

            public function previewCall(
                array $arguments,
                ToolExecutionContext $context,
            ): array {
                return [];
            }

            public function execute(
                array $arguments,
                ToolExecutionContext $context,
            ): ToolResult {
                return ToolResult::text('Unused fixture execution');
            }

            public function isEnabledByDefault(): bool
            {
                return true;
            }

            public function requiresAdmin(): bool
            {
                return false;
            }

            public function getGroup(): string
            {
                return 'test';
            }
        };
    }

    /**
     * @param list<string> $lines
     */
    private function runWithPreview(
        string $uuid,
        string $tool,
        array $lines,
        bool $failed = false,
    ): AgentRun {
        $state = new SuspendedRunState(
            [],
            [ToolCall::function('call', $tool, ['uid' => 42])->toArray()],
            1,
            0,
            0,
            null,
            [],
            null,
            [],
            [
                [
                    'index' => 0,
                    'tool' => $tool,
                    'lines' => $lines,
                    'failed' => $failed,
                ],
            ],
        );
        return new AgentRun(
            uid: 1,
            uuid: $uuid,
            status: 'waiting_for_approval',
            configurationUid: 0,
            configurationIdentifier: 'cfg',
            beUser: 7,
            iterations: 1,
            truncated: false,
            totalPromptTokens: 0,
            totalCompletionTokens: 0,
            totalTokens: 0,
            estimatedCost: 0.0,
            errorClass: '',
            terminationReason: '',
            startedAt: 0,
            finishedAt: 0,
            crdate: 100,
            suspendedState: json_encode($state->toArray(), JSON_THROW_ON_ERROR),
        );
    }
}
