<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Exception;

use InvalidArgumentException;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\EditorAction;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Exception\NrLlmExceptionInterface;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingApprovalException;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Feature\ConversationService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Session\AiSessionRepositoryInterface;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\Exception\ToolInputRequiredException;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpSubjectCredential;
use Netresearch\NrLlm\Specialized\Exception\TranslatorException;
use Netresearch\NrLlm\Specialized\Exception\UnsupportedFormatException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Checks common catches, native parent compatibility and resumable suspension state.
 */
#[CoversNothing]
final class PublicExceptionBoundaryTest extends TestCase
{
    #[Test]
    public function agentValidationIsCatchableByTheCommonMarkerAndNativeParent(): void
    {
        $error = $this->capture(
            static function (): never {
                throw RunNotAwaitingApprovalException::forRun('unknown-run');
            },
        );
        self::assertInstanceOf(RuntimeException::class, $error);
        self::assertInstanceOf(RunNotAwaitingApprovalException::class, $error);
        self::assertSame('unknown-run', $error->runUuid);
    }

    #[Test]
    public function specializedTranslationFailuresRetainTheirContext(): void
    {
        $previous = new RuntimeException('transport failure');
        $error = $this->capture(
            static function () use ($previous): never {
                throw new TranslatorException(
                    'translation failed',
                    'deepl',
                    ['statusCode' => 429],
                    123,
                    $previous,
                );
            },
        );
        self::assertInstanceOf(TranslatorException::class, $error);
        self::assertInstanceOf(RuntimeException::class, $error);
        self::assertSame(123, $error->getCode());
        self::assertSame($previous, $error->getPrevious());
        self::assertSame(429, $error->getStatusCode());
    }

    #[Test]
    public function unsupportedDocumentFormatsAreCatchableWithoutEnumeratingSpecializedTypes(): void
    {
        $error = $this->capture(
            static function (): never {
                throw UnsupportedFormatException::forFormat('unsupported', 'document', ['pdf']);
            },
        );
        self::assertInstanceOf(UnsupportedFormatException::class, $error);
        self::assertSame('document', $error->service);
        self::assertSame(['format' => 'unsupported', 'supported' => ['pdf']], $error->context);
    }

    #[Test]
    public function delegatedCredentialValidationUsesTheCommonMarker(): void
    {
        $error = $this->capture(
            static function (): void {
                new McpSubjectCredential('bad:identifier', ['api'], ['read']);
            },
        );
        self::assertInstanceOf(InvalidArgumentException::class, $error);
        self::assertSame(6331797530, $error->getCode());
    }

    #[Test]
    public function editorActionValidationUsesTheCommonMarker(): void
    {
        $error = $this->capture(
            static function (): void {
                new EditorAction('', 'description', 'icon', ['pages']);
            },
        );
        self::assertInstanceOf(InvalidArgumentException::class, $error);
        self::assertSame(1786406401, $error->getCode());
    }

    #[Test]
    public function aFailedSessionReadBackReachesTheConsumersCommonCatch(): void
    {
        $sessions = $this->createMock(AiSessionRepositoryInterface::class);
        $sessions->expects(self::once())->method('startSession')->willReturn(1);
        $sessions->expects(self::once())->method('findByUuid')->willReturn(null);
        $service = new ConversationService(
            self::createStub(LlmServiceManagerInterface::class),
            $sessions,
            new ConfigurationResolver(),
        );
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('conversation');

        $error = $this->capture(
            static function () use ($service, $configuration): void {
                $service->startSession(AiActorContext::backendUser(1), '', $configuration);
            },
        );
        self::assertInstanceOf(RuntimeException::class, $error);
        self::assertSame(1784600002, $error->getCode());
    }

    /**
     * @param callable(): void $operation
     */
    private function capture(callable $operation): Throwable
    {
        try {
            $operation();
        } catch (NrLlmExceptionInterface $error) {
            return $error;
        }

        self::fail('The invalid public operation must reach the common exception catch.');
    }

    #[Test]
    public function approvalSuspensionKeepsItsStateWhenCaughtBeforeTheCommonMarker(): void
    {
        $state = new SuspendedRunState(
            [['role' => 'user', 'content' => 'continue']],
            [['id' => 'call1', 'name' => 'write', 'arguments' => []]],
            2,
            11,
            3,
            inputSchema: [],
        );
        $signal = ToolApprovalRequiredException::fromState($state);
        self::assertInstanceOf(NrLlmExceptionInterface::class, $signal);
        try {
            $this->throwBoundaryException($signal);
        } catch (ToolApprovalRequiredException $pause) {
            self::assertSame($state, $pause->state);
            self::assertSame($state->toArray(), $pause->state->toArray());
            return;
        } catch (NrLlmExceptionInterface) {
            self::fail(
                'The specific suspension catch must preserve the resumable state before the common failure catch.',
            );
        }
    }

    #[Test]
    public function inputSuspensionKeepsItsStateWhenCaughtBeforeTheCommonMarker(): void
    {
        $state = new SuspendedRunState(
            [['role' => 'user', 'content' => 'continue']],
            [['id' => 'call1', 'name' => 'write', 'arguments' => []]],
            2,
            11,
            3,
            inputToolName: 'write',
            inputSchema: ['type' => 'object'],
        );
        $signal = ToolInputRequiredException::fromState($state);
        self::assertInstanceOf(NrLlmExceptionInterface::class, $signal);
        try {
            $this->throwBoundaryException($signal);
        } catch (ToolInputRequiredException $pause) {
            self::assertSame($state, $pause->state);
            self::assertSame($state->toArray(), $pause->state->toArray());
            return;
        } catch (NrLlmExceptionInterface) {
            self::fail(
                'The specific suspension catch must preserve the resumable state before the common failure catch.',
            );
        }
    }

    /**
     * @throws NrLlmExceptionInterface
     */
    private function throwBoundaryException(NrLlmExceptionInterface $error): never
    {
        throw $error;
    }
}
