<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Governance;

use Error;
use Netresearch\NrLlm\Domain\ValueObject\GovernanceEvent;
use Netresearch\NrLlm\Service\Governance\GovernanceEventRepository;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Governance\Fixture\GovernanceDiagnosticFailureXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(GovernanceEventRepository::class)]
final class GovernanceRecordingDiagnosticsTest extends AbstractUnitTestCase
{
    /**
     * @return iterable<string, array{Throwable, ?Throwable, string}>
     */
    public static function failureKinds(): iterable
    {
        foreach ([
            RuntimeException::class,
            Error::class,
            GovernanceDiagnosticFailureXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX::class,
        ] as $databaseClass) {
            $expectedClass = $databaseClass === GovernanceDiagnosticFailureXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX::class ? 'Netresearch\NrLlm\Tests\Unit\Service\Governance\Fixture\GovernanceDiagnosticFailureXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX' : $databaseClass;
            foreach ([null, RuntimeException::class, Error::class] as $loggerClass) {
                yield $databaseClass . '/' . ($loggerClass ?? 'working logger') => [
                    new $databaseClass(
                        'INSERT secret_sql credential=private-value',
                    ),
                    $loggerClass === null ? null : new $loggerClass('logger-private-value', 221),
                    $expectedClass,
                ];
            }
        }
    }

    #[Test]
    #[DataProvider('failureKinds')]
    public function failureDiagnosticsContainOnlyABoundedExceptionClass(
        Throwable $databaseFailure,
        ?Throwable $loggerFailure,
        string $expectedClass,
    ): void {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('insert')
            ->willThrowException($databaseFailure);
        $pool = self::createStub(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);
        $diagnostic = null;
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('warning')
            ->willReturnCallback(
                static function (
                    string $message,
                    array $context,
                ) use (&$diagnostic, $loggerFailure): void {
                    $diagnostic = [$message, $context];
                    if ($loggerFailure instanceof Throwable) {
                        throw $loggerFailure;
                    }
                },
            );
        $repository = new GovernanceEventRepository($pool, $logger);
        $caught = null;
        try {
            $repository->record(
                new GovernanceEvent(
                    correlationId: 'private-trace',
                    decision: 'tool_denied',
                    reason: 'trustZone',
                    provider: 'private-provider',
                    model: 'private-model',
                    configurationIdentifier: 'private-configuration',
                    beUser: 123,
                    toolName: 'private-tool',
                    agentrunUid: 456,
                    guardrail: 'private-guardrail',
                    detail: 'private-detail',
                ),
            );
        } catch (Throwable $failure) {
            $caught = $failure;
        }

        self::assertNull($caught);
        self::assertSame(
            [
                'Could not record governance event.',
                ['exceptionClass' => $expectedClass],
            ],
            $diagnostic,
        );
    }
}
