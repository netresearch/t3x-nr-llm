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
 * The first capability of the server role (spec 051 A2A-SV4): the client's
 * text is the run's user message, and the answer is one text artifact. With
 * the profile's LLM configuration this is a text check or a summary; the run
 * may use only the read-only tools the profile allows. A message without any
 * text is refused before the run starts.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aTextDomainHandler implements A2aDomainHandlerInterface
{
    public const IDENTIFIER = 'nr_llm.text';

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
        // The text goes into the run as the client sent it; whether it says
        // anything is decided without its outer white space.
        $text    = $message->text();
        $request = new AgentRunRequest(
            $context->configuration,
            [ChatMessage::user($text)],
            $context->actor,
            allowedToolNames: $context->toolNames,
            origin: $context->origin,
        );

        return trim($text) === '' ? A2aHandlerDecision::reject($request) : A2aHandlerDecision::run($request);
    }

    public function artifacts(string $answer): array
    {
        return [['artifactId' => Uuid::v7()->toRfc4122(), 'parts' => [['text' => $answer]]]];
    }
}
