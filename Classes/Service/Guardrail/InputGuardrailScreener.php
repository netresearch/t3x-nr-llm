<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail;

use Netresearch\NrLlm\Domain\Enum\GuardrailVerdict;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Exception\GuardrailApprovalRequiredException;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs the input guardrails over an outgoing message list before it reaches a
 * provider (ADR-087).
 *
 * The output guardrails run inside the provider pipeline (ADR-085), where the
 * prompt payload is not reachable. Input screening therefore runs here, on the
 * send path, where the messages ARE reachable — so a REDACT verdict rewrites the
 * prompt in place (which a middleware-side check could not).
 *
 * Each message's text content is screened in tag order: a REDACT rewrites that
 * message's content and screening continues (a later guardrail may still deny);
 * a DENY / REQUIRE_APPROVAL throws the same typed exception the output side uses,
 * so a caller handles both identically; RETRY — which asks the provider again,
 * meaningless before the call — is ignored. Messages are handled in both their
 * typed {@see ChatMessage} and legacy array forms; a message with no string
 * content passes through untouched (an assistant tool-call turn, a structured
 * payload).
 */
final readonly class InputGuardrailScreener
{
    /**
     * @param iterable<InputGuardrailInterface> $guardrails
     */
    public function __construct(
        #[AutowireIterator(InputGuardrailInterface::TAG_NAME)]
        private iterable $guardrails,
    ) {}

    /**
     * @param list<ChatMessage|array<string, mixed>> $messages
     *
     * @return list<ChatMessage|array<string, mixed>>
     */
    public function screen(array $messages): array
    {
        $screened = [];
        foreach ($messages as $message) {
            $screened[] = $this->screenMessage($message);
        }

        return $screened;
    }

    /**
     * @param ChatMessage|array<string, mixed> $message
     *
     * @return ChatMessage|array<string, mixed>
     */
    private function screenMessage(ChatMessage|array $message): ChatMessage|array
    {
        $content = $this->contentOf($message);
        if ($content === '') {
            return $message;
        }

        $redacted = $content;
        foreach ($this->guardrails as $guardrail) {
            $result = $guardrail->checkInput($redacted);
            switch ($result->verdict) {
                case GuardrailVerdict::ALLOW:
                case GuardrailVerdict::RETRY:
                    // RETRY re-asks the provider; no provider call has happened
                    // yet on the input side, so it is a pass here.
                    break;
                case GuardrailVerdict::REDACT:
                    $redacted = $result->redactedContent ?? $redacted;
                    break;
                case GuardrailVerdict::DENY:
                    throw new GuardrailViolationException(
                        $guardrail::class,
                        $result->reason !== '' ? $result->reason : 'A guardrail denied the prompt.',
                    );
                case GuardrailVerdict::REQUIRE_APPROVAL:
                    throw new GuardrailApprovalRequiredException(
                        $guardrail::class,
                        $result->reason !== '' ? $result->reason : 'A guardrail flagged the prompt for human approval.',
                    );
            }
        }

        if ($redacted === $content) {
            return $message;
        }

        return $this->withContent($message, $redacted);
    }

    /**
     * @param ChatMessage|array<string, mixed> $message
     */
    private function contentOf(ChatMessage|array $message): string
    {
        if ($message instanceof ChatMessage) {
            return $message->content;
        }

        $content = $message['content'] ?? null;

        return is_string($content) ? $content : '';
    }

    /**
     * @param ChatMessage|array<string, mixed> $message
     *
     * @return ChatMessage|array<string, mixed>
     */
    private function withContent(ChatMessage|array $message, string $content): ChatMessage|array
    {
        if ($message instanceof ChatMessage) {
            // Rebuild the immutable VO, preserving role and the tool-turn fields.
            return new ChatMessage($message->getRole(), $content, $message->toolCalls, $message->toolCallId);
        }

        return [...$message, 'content' => $content];
    }
}
