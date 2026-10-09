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
use Netresearch\NrLlm\Domain\ValueObject\SkillCompositionResult;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

/**
 * Places composed skills into a prompt for text-generation calls.
 *
 * Shared by the two service-layer injection sites — {@see
 * \Netresearch\NrLlm\Service\Task\TaskExecutionService} (task skills + the
 * task's configuration skills) and the configuration-driven completion /
 * translation path in {@see \Netresearch\NrLlm\Service\LlmServiceManager}
 * (the resolved configuration's skills). The fenced block of untrusted skills
 * is never placed in the system role: for a plain prompt it is prepended to
 * the prompt string, for a messages list it is prepended to the first
 * user-role message only. Approved instruction sections (ADR-214 item 2) are
 * the one thing that reaches the system message, and only through the
 * channel each method documents. Composition warnings (integrity mismatch,
 * process skills skipped, budget drops) are logged at warning level.
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
     * Compose the skills for a single-string prompt and report what applies.
     *
     * The fenced block is prepended to the prompt. Approved instruction
     * sections (ADR-214 item 2) are returned separately, because a plain
     * prompt has no system message to carry them: the caller hands them to the
     * configuration's system prompt
     * ({@see \Netresearch\NrLlm\Service\ConfigurationCallPlanner::INSTRUCTIONS_OPTION}).
     * They are never folded into the prompt, which is the user turn.
     *
     * Composition runs exactly once, so the applied identifiers attribute the
     * provider-reported usage to the skills that contributed to it.
     *
     * @param list<Skill> $configSkills
     * @param list<Skill> $taskSkills
     *
     * @return array{prompt: string, instructions: string, included: list<string>}
     */
    public function composeIntoPrompt(string $userPrompt, array $configSkills, array $taskSkills = []): array
    {
        $result = $this->composeAndLog($configSkills, $taskSkills);

        return [
            'prompt'       => $this->prepend($result->block, $userPrompt),
            'instructions' => $result->instructions,
            'included'     => $result->included,
        ];
    }

    /**
     * Compose the skills for a message list.
     *
     * The fenced block is prepended to the first user-role message; the system
     * role never receives it, and when no user message is present the list is
     * returned unchanged in that respect.
     *
     * Approved instruction sections (ADR-214 item 2) belong to the system
     * message. When the list already holds one — a caller's own — they are
     * appended to the first system message here. When it holds none, they are
     * returned as `instructions` and NOT placed: adding a system message here
     * would make the shaping stage suppress the configuration's own system
     * prompt and its snippets (ADR-031), so the caller places them after that
     * prompt itself. `instructions` is '' when nothing is left to place.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param list<Skill>                            $configSkills
     * @param list<Skill>                            $taskSkills
     *
     * `pins` names the versions the instruction sections were composed from
     * (ADR-214 item 6), whether placed here or handed back.
     *
     * @return array{messages: list<ChatMessage|array<string, mixed>>, instructions: string, pins: list<SkillPin>}
     */
    public function composeIntoMessages(array $messages, array $configSkills, array $taskSkills = []): array
    {
        $result   = $this->composeAndLog($configSkills, $taskSkills);
        $messages = $this->prependBlockToFirstUserMessage($messages, $result->block);

        if ($result->instructions === '') {
            return ['messages' => $messages, 'instructions' => '', 'pins' => []];
        }

        $placed = self::appendToFirstSystemMessage($messages, $result->instructions);
        if ($placed === null) {
            return ['messages' => $messages, 'instructions' => $result->instructions, 'pins' => $result->instructionPins];
        }

        return ['messages' => $placed, 'instructions' => '', 'pins' => $result->instructionPins];
    }

    /**
     * Append text to the first system-role message of a list's head, or
     * return null when the head holds none.
     *
     * The head is everything before the first user turn. A system message
     * after it may be evicted by the context window manager, which keeps the
     * head and trims from there, so instruction sections are never placed in
     * one: the caller then places them itself.
     *
     * Shared by every place that writes approved instruction sections into a
     * system message that already exists (ADR-214 item 2), so they all join it
     * the same way.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     *
     * @return list<ChatMessage|array<string, mixed>>|null
     */
    public static function appendToFirstSystemMessage(array $messages, string $text): ?array
    {
        foreach ($messages as $index => $message) {
            if ($message instanceof ChatMessage) {
                if ($message->isUser()) {
                    return null;
                }

                if (!$message->isSystem()) {
                    continue;
                }

                $messages[$index] = ChatMessage::system(self::join($message->content, $text));

                return $messages;
            }

            $role = $message['role'] ?? null;
            if ($role === MessageRole::USER->value) {
                return null;
            }

            if ($role !== MessageRole::SYSTEM->value) {
                continue;
            }

            $content = $message[self::KEY_CONTENT] ?? '';
            if (is_array($content)) {
                // A part list: the text joins as one more text part at the end.
                $content[]                 = ['type' => 'text', 'text' => $text];
                $message[self::KEY_CONTENT] = $content;
            } else {
                $message[self::KEY_CONTENT] = self::join(is_string($content) ? $content : '', $text);
            }

            $messages[$index] = $message;

            return $messages;
        }

        return null;
    }

    /**
     * Join a system prompt and appended text the way the snippet block joins
     * the configuration's prompt (ADR-031): a blank line between them.
     */
    public static function join(string $systemPrompt, string $appended): string
    {
        if ($appended === '') {
            return $systemPrompt;
        }

        return $systemPrompt === '' ? $appended : $systemPrompt . self::SEPARATOR . $appended;
    }

    /**
     * @param list<ChatMessage|array<string, mixed>> $messages
     *
     * @return list<ChatMessage|array<string, mixed>>
     */
    private function prependBlockToFirstUserMessage(array $messages, string $block): array
    {
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

        if (!$injected) {
            // The list is still returned unchanged — the block is never
            // escalated into the system role to satisfy a missing user turn.
            // But a composed block that reached no message is a skill set the
            // model never sees, and skills carry instructions and constraints,
            // so silence is the wrong way to report it.
            $this->logger->warning(
                'Skill block composed but not applied: no user message to prepend it to. '
                . "The configuration's skills did not reach the model for this call.",
            );

            return $messages;
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
    private function composeAndLog(array $configSkills, array $taskSkills): SkillCompositionResult
    {
        $result = $this->composer->composeBlock($configSkills, $taskSkills);
        foreach ($result->warnings as $warning) {
            $this->logger->warning($warning);
        }

        return $result;
    }

    private function prepend(string $block, string $userPrompt): string
    {
        if ($block === '') {
            return $userPrompt;
        }

        return $block . self::SEPARATOR . $userPrompt;
    }

    /**
     * @param ChatMessage|array<string, mixed> $message
     */
    private function isUserMessage(ChatMessage|array $message): bool
    {
        if ($message instanceof ChatMessage) {
            return $message->isUser();
        }

        if (($message['role'] ?? null) !== MessageRole::USER->value) {
            return false;
        }

        $content = $message[self::KEY_CONTENT] ?? null;

        // A string is the plain turn. A LIST is the multimodal shape:
        // MessageShaper::normalise() converts only the exact 2-key string/string
        // form into a ChatMessage and passes everything else through for the
        // adapters to convert, so a content array of OpenAI-style parts arrives
        // here intact. Requiring a string sent the block to a later turn, or
        // nowhere.
        //
        // array_is_list, not is_array: an associative content array is not a
        // part list and prepending to it would produce a shape no adapter
        // reads. Such a message is left for the next candidate.
        return is_string($content) || (is_array($content) && array_is_list($content));
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

        $existing = $message[self::KEY_CONTENT] ?? '';

        if (is_array($existing)) {
            // A leading text part, in the OpenAI-style block shape every
            // adapter reads — ClaudeProvider::convertMultimodalContent()
            // translates `{type: text}` into its own format, so this stays
            // provider-neutral. The existing parts keep their order; nothing
            // is merged into them, because a part may be an image.
            $message[self::KEY_CONTENT] = [
                ['type' => 'text', 'text' => $block],
                ...$existing,
            ];

            return $message;
        }

        $message[self::KEY_CONTENT] = $block . self::SEPARATOR . (is_string($existing) ? $existing : '');

        return $message;
    }
}
