<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Session\Fixtures;

use Netresearch\NrLlm\Domain\ValueObject\AiSession;
use Netresearch\NrLlm\Domain\ValueObject\AiSessionMessage;
use Netresearch\NrLlm\Service\Session\AiSessionRepositoryInterface;

/**
 * Stateful in-memory {@see AiSessionRepositoryInterface} for unit-testing the
 * conversation service: it stores sessions and messages so a full send() round
 * (find session, load history, append turns, touch) behaves realistically.
 *
 * @phpstan-type SessionRow array{uuid: string, beUser: int, configId: string, title: string, messageCount: int, lastActivity: int, crdate: int}
 * @phpstan-type MessageRow array{session: int, sequence: int, role: string, content: string, model: string, prompt: int, completion: int, total: int}
 */
final class RecordingAiSessionRepository implements AiSessionRepositoryInterface
{
    public int $nextUid = 1;

    /** @var array<int, SessionRow> */
    public array $sessions = [];

    /** @var list<MessageRow> */
    public array $messages = [];

    /** @var list<array{session: int, messageCount: int}> */
    public array $touchCalls = [];

    public function startSession(string $uuid, int $beUser, string $configurationIdentifier, string $title): int
    {
        $uid                  = $this->nextUid++;
        $this->sessions[$uid] = [
            'uuid'         => $uuid,
            'beUser'       => $beUser,
            'configId'     => $configurationIdentifier,
            'title'        => $title,
            'messageCount' => 0,
            'lastActivity' => 0,
            'crdate'       => 0,
        ];

        return $uid;
    }

    public function appendMessage(
        int $sessionUid,
        int $sequence,
        string $role,
        string $content,
        string $model,
        int $promptTokens,
        int $completionTokens,
        int $totalTokens,
    ): void {
        $this->messages[] = [
            'session'    => $sessionUid,
            'sequence'   => $sequence,
            'role'       => $role,
            'content'    => $content,
            'model'      => $model,
            'prompt'     => $promptTokens,
            'completion' => $completionTokens,
            'total'      => $totalTokens,
        ];
    }

    public function touch(int $sessionUid, int $messageCount): void
    {
        if (isset($this->sessions[$sessionUid])) {
            $this->sessions[$sessionUid]['messageCount'] = $messageCount;
            $this->sessions[$sessionUid]['lastActivity'] = 1;
        }
        $this->touchCalls[] = ['session' => $sessionUid, 'messageCount' => $messageCount];
    }

    public function findByUuid(string $uuid): ?AiSession
    {
        foreach ($this->sessions as $uid => $row) {
            if ($row['uuid'] === $uuid) {
                return new AiSession(
                    $uid,
                    $row['uuid'],
                    $row['beUser'],
                    $row['configId'],
                    $row['title'],
                    $row['messageCount'],
                    $row['lastActivity'],
                    $row['crdate'],
                );
            }
        }

        return null;
    }

    public function findMessages(int $sessionUid): array
    {
        $out = [];
        foreach ($this->messages as $row) {
            if ($row['session'] === $sessionUid) {
                $out[] = new AiSessionMessage(
                    0,
                    $row['session'],
                    $row['sequence'],
                    $row['role'],
                    $row['content'],
                    $row['model'],
                    $row['prompt'],
                    $row['completion'],
                    $row['total'],
                    0,
                );
            }
        }
        usort($out, static fn(AiSessionMessage $a, AiSessionMessage $b): int => $a->sequence <=> $b->sequence);

        return $out;
    }

    public function purgeInactiveSince(int $timestamp): int
    {
        return 0;
    }
}
