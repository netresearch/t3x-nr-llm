<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Middleware;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Exception\GuardrailApprovalRequiredException;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Provider\Middleware\TelemetryMiddleware;
use Netresearch\NrLlm\Service\Telemetry\TelemetryRecord;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Fixture\InMemoryTelemetryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\AspectInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\Exception\AspectNotFoundException;

#[CoversClass(TelemetryMiddleware::class)]
#[CoversClass(TelemetryRecord::class)]
final class TelemetryMiddlewareTest extends AbstractUnitTestCase
{
    #[Test]
    public function recordsOneSuccessRowWithTheExpectedFields(): void
    {
        $repository = $this->recordingRepository();

        $context = new ProviderCallContext(ProviderOperation::Chat, 'corr-success');
        $result  = $this->pipeline($repository)->run(
            $context->withConfiguration($this->configuration('primary')),
            static fn(): string => 'answer',
        );

        self::assertSame('answer', $result);
        self::assertCount(1, $repository->records);

        $record = $repository->records[0];
        self::assertTrue($record->success);
        self::assertSame('', $record->errorClass);
        self::assertSame('chat', $record->operation);
        self::assertSame('corr-success', $record->correlationId);
        self::assertSame('primary', $record->configurationIdentifier);
        // Bare transient configuration carries no model, so provider/model are
        // empty — the sourcing path (getProviderType/getModelId) is exercised.
        self::assertSame('', $record->provider);
        self::assertSame('', $record->model);
        self::assertGreaterThanOrEqual(0, $record->latencyMs);
        self::assertFalse($record->cacheHit);
        self::assertSame(0, $record->fallbackAttempts);
    }

    #[Test]
    public function recordsFailureRowWithExceptionFqcnAndReThrows(): void
    {
        $repository = $this->recordingRepository();

        $context = new ProviderCallContext(ProviderOperation::Completion, 'corr-fail');

        $caught = false;
        try {
            $this->pipeline($repository)->run(
                $context->withConfiguration($this->configuration('primary')),
                static function (): string {
                    throw new RuntimeException('boom', 1495872184);
                },
            );
        } catch (RuntimeException $e) {
            $caught = true;
            self::assertSame('boom', $e->getMessage());
        }

        self::assertTrue($caught, 'The original exception must propagate.');
        self::assertCount(1, $repository->records);
        $record = $repository->records[0];
        self::assertFalse($record->success);
        // The exception FQCN is stored, never the message (privacy).
        self::assertSame(RuntimeException::class, $record->errorClass);
    }

    /**
     * The guardrail now runs INSIDE this layer (priority 90), so a guardrail
     * policy outcome throws through Telemetry. It must be recorded as a SUCCESS
     * (the provider call itself succeeded) so a policy denial does not distort the
     * provider failure-rate — the semantics that held when the guardrail was above
     * Telemetry.
     */
    #[Test]
    public function recordsAGuardrailDenialAsASuccessfulProviderRunAndReThrows(): void
    {
        $repository = $this->recordingRepository();

        $caught = false;
        try {
            $this->pipeline($repository)->run(
                (new ProviderCallContext(ProviderOperation::Chat, 'corr-guardrail'))
                    ->withConfiguration($this->configuration('primary')),
                static fn(): string => throw new GuardrailViolationException('Some\\Guardrail', 'blocked'),
            );
        } catch (GuardrailViolationException) {
            $caught = true;
        }

        self::assertTrue($caught, 'The guardrail exception must still propagate.');
        self::assertCount(1, $repository->records);
        self::assertTrue($repository->records[0]->success);
        self::assertSame('', $repository->records[0]->errorClass);
    }

    #[Test]
    public function recordsAGuardrailApprovalRequirementAsASuccessfulProviderRun(): void
    {
        $repository = $this->recordingRepository();

        $caught = false;
        try {
            $this->pipeline($repository)->run(
                (new ProviderCallContext(ProviderOperation::Chat, 'corr-approval'))
                    ->withConfiguration($this->configuration('primary')),
                static fn(): string => throw new GuardrailApprovalRequiredException('Some\\Guardrail', 'needs approval'),
            );
        } catch (GuardrailApprovalRequiredException) {
            $caught = true;
        }

        self::assertTrue($caught);
        self::assertCount(1, $repository->records);
        self::assertTrue($repository->records[0]->success);
        self::assertSame('', $repository->records[0]->errorClass);
    }

    #[Test]
    public function readsCacheHitSignalSetByAnInnerLayer(): void
    {
        $repository = $this->recordingRepository();

        $context = (new ProviderCallContext(ProviderOperation::Embedding, 'corr'))
            ->withConfiguration($this->configuration('primary'));
        $context->telemetrySignals->recordCacheHit();

        $this->pipeline($repository)->run(
            $context,
            static fn(): string => 'x',
        );

        self::assertTrue($repository->records[0]->cacheHit);
    }

    #[Test]
    public function readsFallbackAttemptsSignalSetByAnInnerLayer(): void
    {
        $repository = $this->recordingRepository();

        $context = (new ProviderCallContext(ProviderOperation::Chat, 'corr'))
            ->withConfiguration($this->configuration('primary'));
        $context->telemetrySignals->recordFallbackAttempt();
        $context->telemetrySignals->recordFallbackAttempt();

        $this->pipeline($repository)->run(
            $context,
            static fn(): string => 'x',
        );

        self::assertSame(2, $repository->records[0]->fallbackAttempts);
    }

    #[Test]
    public function isNoOpWhenDisabled(): void
    {
        $repository = $this->recordingRepository();

        $result = $this->pipeline($repository, enabled: false)->run(
            (new ProviderCallContext(ProviderOperation::Chat, 'corr'))
                ->withConfiguration($this->configuration('primary')),
            static fn(): string => 'answer',
        );

        self::assertSame('answer', $result, 'The terminal must still run when telemetry is disabled.');
        self::assertCount(0, $repository->records);
    }

    #[Test]
    public function telemetryWriteFailureDoesNotBreakTheCall(): void
    {
        $failingRepository               = new InMemoryTelemetryRepository();
        $failingRepository->failOnRecord = new RuntimeException('db down');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $middleware = new TelemetryMiddleware(
            $failingRepository,
            $this->contextWithAmbientUser(0),
            $this->extensionConfiguration(true),
            $logger,
        );

        $result = (new MiddlewarePipeline([$middleware]))->run(
            (new ProviderCallContext(ProviderOperation::Chat, 'corr'))
                ->withConfiguration($this->configuration('primary')),
            static fn(): string => 'answer',
        );

        self::assertSame('answer', $result);
    }

    #[Test]
    public function loggerFailureDuringTelemetryDoesNotBreakTheCall(): void
    {
        // safeRecord() runs inside handle()'s finally; a throw escaping the
        // catch would replace the observed call's result/exception. The
        // logger can itself throw (e.g. TYPO3 FileWriter on a full disk), so
        // even a failing logger must not surface.
        $failingRepository               = new InMemoryTelemetryRepository();
        $failingRepository->failOnRecord = new RuntimeException('db down');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willThrowException(new RuntimeException('log disk full'));

        $middleware = new TelemetryMiddleware(
            $failingRepository,
            $this->contextWithAmbientUser(0),
            $this->extensionConfiguration(true),
            $logger,
        );

        $result = (new MiddlewarePipeline([$middleware]))->run(
            (new ProviderCallContext(ProviderOperation::Chat, 'corr'))
                ->withConfiguration($this->configuration('primary')),
            static fn(): string => 'answer',
        );

        self::assertSame('answer', $result);
    }

    #[Test]
    public function resolvesBeUserFromMetadata(): void
    {
        $repository = $this->recordingRepository();

        $context = new ProviderCallContext(
            ProviderOperation::Chat,
            'corr',
            metadata: [BudgetMiddleware::METADATA_BE_USER_UID => 42],
        );

        $this->pipeline($repository)->run(
            $context->withConfiguration($this->configuration('primary')),
            static fn(): string => 'x',
        );

        self::assertSame(42, $repository->records[0]->beUser);
    }

    #[Test]
    public function resolvesBeUserFromAmbientAspectWhenMetadataAbsent(): void
    {
        $repository = $this->recordingRepository();

        $middleware = new TelemetryMiddleware(
            $repository,
            $this->contextWithAmbientUser(7),
            $this->extensionConfiguration(true),
            new NullLogger(),
        );

        (new MiddlewarePipeline([$middleware]))->run(
            (new ProviderCallContext(ProviderOperation::Chat, 'corr'))
                ->withConfiguration($this->configuration('primary')),
            static fn(): string => 'x',
        );

        self::assertSame(7, $repository->records[0]->beUser);
    }

    #[Test]
    public function resolvesBeUserToZeroWhenNoBackendUserAspect(): void
    {
        $repository = $this->recordingRepository();

        $context = self::createStub(Context::class);
        $context->method('getAspect')->willThrowException(new AspectNotFoundException('no aspect', 1234567890));

        $middleware = new TelemetryMiddleware(
            $repository,
            $context,
            $this->extensionConfiguration(true),
            new NullLogger(),
        );

        (new MiddlewarePipeline([$middleware]))->run(
            (new ProviderCallContext(ProviderOperation::Chat, 'corr'))
                ->withConfiguration($this->configuration('primary')),
            static fn(): string => 'x',
        );

        self::assertSame(0, $repository->records[0]->beUser);
    }

    // -----------------------------------------------------------------------
    // Test helpers
    // -----------------------------------------------------------------------

    /**
     * In-memory repository that captures the DTOs the middleware builds, so the
     * assertions verify what TelemetryMiddleware produced — never a mock return.
     */
    private function recordingRepository(): InMemoryTelemetryRepository
    {
        return new InMemoryTelemetryRepository();
    }

    private function pipeline(InMemoryTelemetryRepository $repository, bool $enabled = true): MiddlewarePipeline
    {
        $middleware = new TelemetryMiddleware(
            $repository,
            $this->contextWithAmbientUser(0),
            $this->extensionConfiguration($enabled),
            new NullLogger(),
        );

        return new MiddlewarePipeline([$middleware]);
    }

    private function extensionConfiguration(bool $enabled): ExtensionConfiguration&MockObject
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')
            ->willReturn(['telemetry' => ['enabled' => $enabled ? '1' : '0']]);

        return $extensionConfiguration;
    }

    private function contextWithAmbientUser(int $id): Context
    {
        $aspect = self::createStub(AspectInterface::class);
        $aspect->method('get')->willReturn($id);

        $context = self::createStub(Context::class);
        $context->method('getAspect')->willReturn($aspect);

        return $context;
    }

    private function configuration(string $identifier): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier($identifier);

        return $configuration;
    }
}
