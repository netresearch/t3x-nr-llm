<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server\Handler;

use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aMessage;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSkillProfile;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Symfony\Component\Uid\Uuid;

/**
 * Checks a text the client wrote or edited, without generating one (ADR-302):
 * the client's text is the run's answer as it was sent, and the end-of-run
 * check judges it against the rules of the profile's configuration. No
 * generator call and no tool call happen, whatever the profile allows and
 * whatever the text asks for.
 *
 * A message without any text is refused before the run starts.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aEditorialCheckHandler implements A2aEndCheckedHandlerInterface
{
    public const IDENTIFIER = 'nr_llm.editorial_check';

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function decide(A2aMessage $message, A2aSkillProfile $profile, A2aRunContext $context): A2aHandlerDecision
    {
        $text    = $message->text();
        $request = new AgentRunRequest(
            $context->configuration,
            [ChatMessage::user($text)],
            $context->actor,
            // No tool, whatever the profile lists: the run calls nothing.
            allowedToolNames: [],
            origin: $context->origin,
        );

        return trim($text) === '' ? A2aHandlerDecision::reject($request) : A2aHandlerDecision::run($request);
    }

    public function artifacts(string $answer): array
    {
        return [['artifactId' => Uuid::v7()->toRfc4122(), 'parts' => [['text' => $this->deliverableText($answer)]]]];
    }

    public function deliverableText(string $keptAnswer): string
    {
        return $keptAnswer;
    }

    public function checksOnly(): bool
    {
        return true;
    }
}
