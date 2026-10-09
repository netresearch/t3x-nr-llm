<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Context;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Service\Context\ContextWindowManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Admission of a new system section (ADR-214 item 7): measured against the
 * floor the fit reaches — the head plus the newest turn — with 10 % of the
 * budget held back, so an admitted section can always be sent and a refused
 * one is never appended.
 */
#[CoversClass(ContextWindowManager::class)]
final class ContextWindowSectionAdmissionTest extends TestCase
{
    #[Test]
    public function aSmallSectionIsAdmittedAndAnOversizedOneRefused(): void
    {
        $manager  = new ContextWindowManager();
        $messages = [ChatMessage::system('Identity.'), ChatMessage::user('Write the note.')];

        self::assertTrue($manager->admitsSection($messages, 'Short section.', $this->config(10000, 1000), null));
        self::assertFalse($manager->admitsSection($messages, str_repeat('word ', 20000), $this->config(10000, 1000), null));
    }

    /**
     * Older turns are what the fit drops, so they do not count against a
     * section; the newest turn is never dropped, so it does.
     */
    #[Test]
    public function olderTurnsDoNotCountAndTheNewestTurnDoes(): void
    {
        $manager = new ContextWindowManager();
        $config  = $this->config(10000, 1000);
        $big     = str_repeat('history ', 4000);

        $oldIsBig = [
            ChatMessage::system('Identity.'),
            ChatMessage::user('Write the note.'),
            ChatMessage::assistant($big),
            ChatMessage::user('And now?'),
            ChatMessage::assistant('Short.'),
        ];
        self::assertTrue($manager->admitsSection($oldIsBig, 'Short section.', $config, null));

        $newestIsBig = [
            ChatMessage::system('Identity.'),
            ChatMessage::user('Write the note.'),
            ChatMessage::assistant('Short.'),
            ChatMessage::user('And now?'),
            ChatMessage::assistant($big),
        ];
        self::assertFalse($manager->admitsSection($newestIsBig, 'Short section.', $config, null));
    }

    /**
     * The headroom: a section that the fit would still send is refused once
     * it leaves less than 10 % of the budget.
     */
    #[Test]
    public function aSectionThatOnlyFitsWithoutTheHeadroomIsRefused(): void
    {
        $manager  = new ContextWindowManager();
        $config   = $this->config(10000, 1000);
        $messages = [ChatMessage::system('Identity.'), ChatMessage::user('Write the note.')];

        $words = 100;
        while ($manager->admitsSection($messages, str_repeat('word ', $words), $config, null)) {
            $words += 20;
        }

        $section = str_repeat('word ', $words);
        $fit     = $manager->fit([ChatMessage::system('Identity.' . "\n\n" . $section), ChatMessage::user('Write the note.')], $config, null, null);

        self::assertFalse($fit->overflowAtFloor, 'the refused section would still have fit without the headroom');
        self::assertGreaterThan((int)floor($fit->budget * 0.9), $fit->estimatedTokens);
    }

    #[Test]
    public function aWindowWithoutABudgetAdmitsNothing(): void
    {
        $manager = new ContextWindowManager();

        self::assertFalse($manager->admitsSection([ChatMessage::user('x')], 'Short section.', $this->config(1000, 5000), null));
    }

    private function config(int $contextLength, int $maxOutputTokens): LlmConfiguration
    {
        $model = new Model();
        $model->setContextLength($contextLength);
        $model->setMaxOutputTokens($maxOutputTokens);

        $configuration = new LlmConfiguration();
        $configuration->setLlmModel($model);

        return $configuration;
    }
}
