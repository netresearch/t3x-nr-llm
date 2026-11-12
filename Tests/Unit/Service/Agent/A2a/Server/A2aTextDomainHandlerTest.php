<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aMessage;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSkillProfile;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aRunContext;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aTextDomainHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The text handler runs the client's text as it came (spec 051 A2A-SV4).
 */
#[CoversClass(A2aTextDomainHandler::class)]
final class A2aTextDomainHandlerTest extends TestCase
{
    #[Test]
    public function theRunGetsTheTextAsTheClientSentIt(): void
    {
        $text     = "  keep the indent\n\n";
        $decision = $this->decide($text);

        self::assertFalse($decision['rejected']);
        self::assertSame($text, $decision['content']);
    }

    #[Test]
    public function aMessageOfWhiteSpaceOnlyIsRejected(): void
    {
        self::assertTrue($this->decide(" \n\t ")['rejected']);
    }

    /**
     * @return array{rejected: bool, content: string}
     */
    private function decide(string $text): array
    {
        $decision = (new A2aTextDomainHandler())->decide(
            A2aMessage::userText('m-1', $text),
            A2aSkillProfile::fromRow(['uid' => 1, 'skill_id' => 'summary', 'handler' => A2aTextDomainHandler::IDENTIFIER, 'configuration_uid' => 1]),
            new A2aRunContext(new LlmConfiguration(), AiActorContext::backendUser(7), [], ''),
        );

        return ['rejected' => $decision->rejected, 'content' => $decision->request?->messages[0]->content ?? ''];
    }
}
