<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRunRequestCodec;
use Netresearch\NrLlm\Service\Agent\Exception\RunConfigurationGoneException;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The queued-request payload is the only place a run request has to survive
 * outside a process, so what matters is that the two directions agree: every
 * field {@see AgentRunRequestCodec::dehydrate()} writes must come back out of
 * {@see AgentRunRequestCodec::rehydrate()} with the same meaning.
 */
#[CoversClass(AgentRunRequestCodec::class)]
final class AgentRunRequestCodecTest extends TestCase
{
    private LlmConfigurationRepository&MockObject $configurationRepository;

    private LlmConfiguration $configuration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configuration = new LlmConfiguration();
        $this->configuration->setIdentifier('test.configuration');

        $this->configurationRepository = $this->createMock(LlmConfigurationRepository::class);
    }

    private function codec(): AgentRunRequestCodec
    {
        return new AgentRunRequestCodec($this->configurationRepository);
    }

    /**
     * A queued run row carrying the given serialised request.
     */
    private function queuedRun(string $payload, int $beUser = 7): AgentRun
    {
        return new AgentRun(
            uid: 1,
            uuid: 'run-uuid',
            status: 'queued',
            configurationUid: 42,
            configurationIdentifier: 'test.configuration',
            beUser: $beUser,
            iterations: 0,
            truncated: false,
            totalPromptTokens: 0,
            totalCompletionTokens: 0,
            totalTokens: 0,
            estimatedCost: 0.0,
            errorClass: '',
            terminationReason: '',
            startedAt: 0,
            finishedAt: 0,
            crdate: 0,
            queuedRequest: $payload,
        );
    }

    #[Test]
    public function roundTripPreservesTheRequestFields(): void
    {
        $request = new AgentRunRequest(
            configuration: $this->configuration,
            messages: [['role' => 'user', 'content' => 'hello']],
            actor: AiActorContext::backendUser(7),
            allowedToolNames: ['read_page', 'read_record'],
            options: new ToolOptions(temperature: 0.4),
            maxIterations: 5,
            captureRaw: true,
        );

        $payload = json_encode($this->codec()->dehydrate($request));
        self::assertIsString($payload);

        $this->configurationRepository->method('findByUid')->willReturn($this->configuration);
        $restored = $this->codec()->rehydrate($this->queuedRun($payload));

        self::assertSame([['role' => 'user', 'content' => 'hello']], $restored->messages);
        self::assertSame(['read_page', 'read_record'], $restored->allowedToolNames);
        self::assertSame(5, $restored->maxIterations);
        self::assertTrue($restored->captureRaw);
        self::assertSame(7, $restored->actor->backendUserUid);
        self::assertSame($this->configuration, $restored->configuration);
    }

    /**
     * ToolOptions::toArray() drops the budget and idempotency fields, so the
     * codec carries them out of band. A queued run must hit the same budget
     * gate and the same provider-call dedup as the direct path.
     */
    #[Test]
    public function roundTripCarriesTheBudgetAndIdempotencyFieldsOutOfBand(): void
    {
        $options = (new ToolOptions())
            ->withPlannedCost(0.25)
            ->withIdempotencyKey('idem-key-1');

        $request = new AgentRunRequest(
            configuration: $this->configuration,
            messages: [],
            actor: AiActorContext::backendUser(7),
            options: $options,
        );

        $dehydrated = $this->codec()->dehydrate($request);
        self::assertSame(0.25, $dehydrated['plannedCost']);
        self::assertSame('idem-key-1', $dehydrated['idempotencyKey']);

        $payload = json_encode($dehydrated);
        self::assertIsString($payload);

        $this->configurationRepository->method('findByUid')->willReturn($this->configuration);
        $restored = $this->codec()->rehydrate($this->queuedRun($payload));

        self::assertNotNull($restored->options);
        self::assertSame(0.25, $restored->options->getPlannedCost());
        self::assertSame('idem-key-1', $restored->options->getIdempotencyKey());
    }

    /**
     * Same channel and same reason as the budget fields above (#847): the caller
     * identity is call metadata, not a provider option, so `toArray()` omits it.
     * Without carrying it out of band a queued run is unattributed for its whole
     * length even though the enqueuing request named its caller — and the run
     * then splits between its own extension and "Unattributed", so neither
     * number is the run's cost.
     */
    #[Test]
    public function roundTripCarriesTheCallerIdentityOutOfBand(): void
    {
        $options = (new ToolOptions())->withCallerSource('nr_mcp_agent', 'chatTurn');

        $request = new AgentRunRequest(
            configuration: $this->configuration,
            messages: [],
            actor: AiActorContext::backendUser(7),
            options: $options,
        );

        $dehydrated = $this->codec()->dehydrate($request);
        self::assertSame('nr_mcp_agent', $dehydrated['callerSourceExtension']);
        self::assertSame('chatTurn', $dehydrated['callerSourceOperation']);

        // It must travel out of band, not inside the serialised options: that
        // array is what rebuilds the provider request, and the calling
        // extension's key must never be sent upstream.
        self::assertIsArray($dehydrated['options']);
        self::assertArrayNotHasKey('callerSourceExtension', $dehydrated['options']);

        $payload = json_encode($dehydrated);
        self::assertIsString($payload);

        $this->configurationRepository->method('findByUid')->willReturn($this->configuration);
        $restored = $this->codec()->rehydrate($this->queuedRun($payload));

        self::assertNotNull($restored->options);
        self::assertSame('nr_mcp_agent', $restored->options->getCallerSourceExtension());
        self::assertSame('chatTurn', $restored->options->getCallerSourceOperation());
    }

    /**
     * An unannotated request must rehydrate unannotated rather than picking up
     * an empty identity: `''` on the row reads as "attributed to nothing", which
     * is a different claim from "not attributed".
     */
    #[Test]
    public function anUnannotatedRequestRehydratesWithoutACallerIdentity(): void
    {
        $request = new AgentRunRequest(
            configuration: $this->configuration,
            messages: [],
            actor: AiActorContext::backendUser(7),
            options: new ToolOptions(),
        );

        $payload = json_encode($this->codec()->dehydrate($request));
        self::assertIsString($payload);

        $this->configurationRepository->method('findByUid')->willReturn($this->configuration);
        $restored = $this->codec()->rehydrate($this->queuedRun($payload));

        self::assertNotNull($restored->options);
        self::assertNull($restored->options->getCallerSourceExtension());
    }

    /**
     * A null augmentation must stay null: a non-null one makes the loop bake the
     * effective system prompt into the transcript, which a null-augmentation run
     * would not do.
     */
    #[Test]
    public function aNullAugmentationSurvivesAsNull(): void
    {
        $request = new AgentRunRequest(
            configuration: $this->configuration,
            messages: [],
            actor: AiActorContext::backendUser(7),
        );

        $dehydrated = $this->codec()->dehydrate($request);
        self::assertNull($dehydrated['augmentation']);

        $payload = json_encode($dehydrated);
        self::assertIsString($payload);

        $this->configurationRepository->method('findByUid')->willReturn($this->configuration);
        self::assertNull($this->codec()->rehydrate($this->queuedRun($payload))->augmentation);
    }

    /**
     * A row queued before actors were persisted has no 'actor' key. It falls
     * back to the stored be_user id, so an in-flight upgrade never loses or
     * invents privilege.
     */
    #[Test]
    public function aPayloadWithoutAnActorFallsBackToTheRunsBackendUser(): void
    {
        $this->configurationRepository->method('findByUid')->willReturn($this->configuration);

        $restored = $this->codec()->rehydrate($this->queuedRun('{"messages":[]}', beUser: 13));

        self::assertSame(13, $restored->actor->backendUserUid);
    }

    #[Test]
    public function aConfigurationDeletedWhileQueuedIsReportedAsGone(): void
    {
        $this->configurationRepository->method('findByUid')->willReturn(null);

        $this->expectException(RunConfigurationGoneException::class);
        $this->codec()->rehydrate($this->queuedRun('{"messages":[]}'));
    }

    #[Test]
    public function anUndecodablePayloadIsRejected(): void
    {
        $this->configurationRepository->method('findByUid')->willReturn($this->configuration);

        $this->expectException(RuntimeException::class);
        $this->codec()->rehydrate($this->queuedRun('not json'));
    }
}
