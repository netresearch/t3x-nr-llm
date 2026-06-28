<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\MessageRole;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

/**
 * Prepends the composed skill block to the *user* prompt for text-generation
 * calls.
 *
 * Shared by the two service-layer injection sites — {@see
 * \Netresearch\NrLlm\Service\Task\TaskExecutionService} (task skills + the
 * task's configuration skills) and the configuration-driven completion /
 * translation path in {@see \Netresearch\NrLlm\Service\LlmServiceManager}
 * (the resolved configuration's skills). The block is never placed in the
 * system role: for a plain prompt it is prepended to the prompt string, for a
 * messages list it is prepended to the first user-role message only (the
 * system message is left untouched). Composition warnings (checksum mismatch,
 * budget drops) are logged at warning level.
 */
final readonly class SkillInjectionService
{
    private const SEPARATOR = "\n\n";

    private const KEY_CONTENT = 'content';

    public function __construct(
        private SkillComposer $composer,
        private LoggerInterface $logger,
    ) {}

    /**
     * Prepend the composed skill block to a single-string user prompt.
     *
     * @param list<Skill> $configSkills
     * @param list<Skill> $taskSkills
     */
    public function augmentPrompt(string $userPrompt, array $configSkills, array $taskSkills = []): string
    {
        $block = $this->composeAndLog($configSkills, $taskSkills);
        if ($block === '') {
            return $userPrompt;
        }

        return $block . self::SEPARATOR . $userPrompt;
    }

    /**
     * Prepend the composed skill block to the first user-role message.
     *
     * The system role is never modified. When no user message is present the
     * list is returned unchanged — the block is never escalated into the
     * system role to satisfy a missing user turn.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param list<Skill>                            $configSkills
     * @param list<Skill>                            $taskSkills
     *
     * @return list<ChatMessage|array<string, mixed>>
     */
    public function augmentMessages(array $messages, array $configSkills, array $taskSkills = []): array
    {
        $block = $this->composeAndLog($configSkills, $taskSkills);
        if ($block === '') {
            return $messages;
        }

        $augmented = [];
        $injected  = false;
        foreach ($messages as $message) {
            if (!$injected && $this->isUserMessage($message)) {
                $augmented[] = $this->prependToUserMessage($message, $block);
                $injected    = true;
                continue;
            }

            $augmented[] = $message;
        }

        return $augmented;
    }

    /**
     * Flatten an ObjectStorage of skills into a plain list for the composer.
     *
     * @param ObjectStorage<Skill> $storage
     *
     * @return list<Skill>
     */
    public static function toList(ObjectStorage $storage): array
    {
        $skills = [];
        foreach ($storage as $skill) {
            if ($skill instanceof Skill) {
                $skills[] = $skill;
            }
        }

        return $skills;
    }

    /**
     * @param list<Skill> $configSkills
     * @param list<Skill> $taskSkills
     */
    private function composeAndLog(array $configSkills, array $taskSkills): string
    {
        $result = $this->composer->composeBlock($configSkills, $taskSkills);
        foreach ($result->warnings as $warning) {
            $this->logger->warning($warning);
        }

        return $result->block;
    }

    /**
     * @param ChatMessage|array<string, mixed> $message
     */
    private function isUserMessage(ChatMessage|array $message): bool
    {
        if ($message instanceof ChatMessage) {
            return $message->isUser();
        }

        return ($message['role'] ?? null) === MessageRole::USER->value
            && is_string($message[self::KEY_CONTENT] ?? null);
    }

    /**
     * @param ChatMessage|array<string, mixed> $message
     *
     * @return ChatMessage|array<string, mixed>
     */
    private function prependToUserMessage(ChatMessage|array $message, string $block): ChatMessage|array
    {
        if ($message instanceof ChatMessage) {
            return ChatMessage::user($block . self::SEPARATOR . $message->content);
        }

        $existing                  = $message[self::KEY_CONTENT] ?? '';
        $message[self::KEY_CONTENT] = $block . self::SEPARATOR . (is_string($existing) ? $existing : '');

        return $message;
    }
}
