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
use Netresearch\NrLlm\Service\Tool\RequiresInputInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Symfony\Component\Uid\Uuid;

use function array_filter;
use function array_values;

/**
 * An editorial text for use outside TYPO3 (ADR-302): the client's text is the
 * run's user message, the run may use the read-only tools the profile allows
 * that ask the client nothing,
 * and its final answer is checked against the rules of the profile's
 * configuration before it is delivered, also when it is short and also when
 * the run only read. The answer is delivered as one text artifact.
 *
 * Like {@see A2aTextDomainHandler}, with the end-of-run check. A message
 * without any text is refused before the run starts.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aEditorialTextHandler implements A2aEndCheckedHandlerInterface
{
    public const IDENTIFIER = 'nr_llm.editorial_text';

    public function __construct(
        private ToolRegistry $tools,
        private ToolEffectResolver $effects,
    ) {}

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
            allowedToolNames: $this->readingTools($context->toolNames),
            origin: $context->origin,
        );

        return trim($text) === '' ? A2aHandlerDecision::reject($request) : A2aHandlerDecision::run($request);
    }

    /**
     * The tools of the profile that read and ask nobody (ADR-302). A writing
     * tool would leave drafts behind a text the check stops, and a question to
     * the client would carry model text past the check: the question of a
     * pause is delivered before the final answer exists.
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function readingTools(array $names): array
    {
        return array_values(array_filter(
            $names,
            fn (string $name): bool => $this->effects->declaresRead($name) && !$this->tools->get($name) instanceof RequiresInputInterface,
        ));
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
        return false;
    }
}
