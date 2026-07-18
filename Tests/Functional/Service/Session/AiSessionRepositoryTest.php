<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Session;

use Netresearch\NrLlm\Domain\ValueObject\AiSessionMessage;
use Netresearch\NrLlm\Service\Session\AiSessionRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * End-to-end round-trip of the conversation-session store against the real
 * schema, mirroring the telemetry repository's functional test: the private
 * raw-SQL repository is instantiated directly with the real ConnectionPool.
 */
#[CoversClass(AiSessionRepository::class)]
final class AiSessionRepositoryTest extends AbstractFunctionalTestCase
{
    private AiSessionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);

        $this->repository = new AiSessionRepository($connectionPool);
    }

    #[Test]
    public function aSessionRoundTripsWithItsOrderedMessages(): void
    {
        $uuid = '11111111-1111-1111-1111-111111111111';
        $uid  = $this->repository->startSession($uuid, 7, 'cfg-chat', 'Greeting');

        $running = $this->repository->findByUuid($uuid);
        self::assertNotNull($running);
        self::assertSame('Greeting', $running->title);
        self::assertSame(7, $running->beUser);
        self::assertSame('cfg-chat', $running->configurationIdentifier);
        self::assertSame(0, $running->messageCount);

        $this->repository->appendMessage($uid, 0, 'user', 'Hi', '', 0, 0, 0);
        $this->repository->appendMessage($uid, 1, 'assistant', 'Hello', 'model-x', 5, 3, 8);
        $this->repository->touch($uid, 2);

        $reloaded = $this->repository->findByUuid($uuid);
        self::assertNotNull($reloaded);
        self::assertSame(2, $reloaded->messageCount);
        self::assertGreaterThan(0, $reloaded->lastActivity);

        $messages = $this->repository->findMessages($uid);
        self::assertCount(2, $messages);
        self::assertSame(['user', 'assistant'], array_map(static fn(AiSessionMessage $m): string => $m->role, $messages));
        self::assertSame('Hello', $messages[1]->content);
        self::assertSame('model-x', $messages[1]->model);
        self::assertSame(8, $messages[1]->totalTokens);
        // Rehydrates into a ChatMessage for replay.
        self::assertSame('Hello', $messages[1]->toChatMessage()->content);
    }

    #[Test]
    public function purgeInactiveSinceRemovesSessionsAndTheirMessages(): void
    {
        $uuid = '22222222-2222-2222-2222-222222222222';
        $uid  = $this->repository->startSession($uuid, 0, '', '');
        $this->repository->appendMessage($uid, 0, 'user', 'Hi', '', 0, 0, 0);

        $deleted = $this->repository->purgeInactiveSince(time() + 3600);

        self::assertGreaterThanOrEqual(1, $deleted);
        self::assertNull($this->repository->findByUuid($uuid));
        self::assertSame([], $this->repository->findMessages($uid));
    }
}
