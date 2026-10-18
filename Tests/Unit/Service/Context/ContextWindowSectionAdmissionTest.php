<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Context;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Service\Context\ContextWindowManager;
use Netresearch\NrLlm\Service\Option\ChatOptions;
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

    /**
     * A shared instance carries the calibration of the run it served last.
     * After beginRun() an admission measures with the seed again: a section
     * that the raised calibration refuses is admitted.
     */
    #[Test]
    public function beginRunDropsTheCalibrationAnotherRunRaised(): void
    {
        $manager  = new ContextWindowManager();
        $config   = $this->config(10000, 1000);
        $messages = [ChatMessage::system('Identity.'), ChatMessage::user('Write the note.')];

        $words = 100;
        while ($manager->admitsSection($messages, str_repeat('word ', $words + 20), $config, null)) {
            $words += 20;
        }

        $section = str_repeat('word ', $words);
        self::assertTrue($manager->admitsSection($messages, $section, $config, null));

        // Another run: its provider reports twice the estimate.
        $first = $manager->fit($messages, $config, null, null);
        $manager->fit($messages, $config, null, UsageStatistics::fromTokens($first->estimatedTokens * 2, 1));

        self::assertFalse($manager->admitsSection($messages, $section, $config, null), 'the raised calibration refuses it');

        $manager->beginRun();

        self::assertTrue($manager->admitsSection($messages, $section, $config, null));
    }

    #[Test]
    public function aWindowWithoutABudgetAdmitsNothing(): void
    {
        $manager = new ContextWindowManager();

        self::assertFalse($manager->admitsSection([ChatMessage::user('x')], 'Short section.', $this->config(1000, 5000), null));
    }

    /**
     * A window that is not known admits nothing: no fallback size stands in
     * for it. The same section is admitted by a known window.
     */
    #[Test]
    public function anUnknownWindowAdmitsNothing(): void
    {
        $manager  = new ContextWindowManager();
        $messages = [ChatMessage::system('Identity.'), ChatMessage::user('Write the note.')];

        self::assertTrue($manager->admitsSection($messages, 'Short section.', $this->config(10000, 1000), null));
        self::assertFalse($manager->admitsSection($messages, 'Short section.', $this->config(0, 1000), null));
        self::assertFalse($manager->admitsSection($messages, 'Short section.', $this->config(-5, 1000), null));
        self::assertFalse($manager->admitsSection($messages, 'Short section.', new LlmConfiguration(), null), 'no model at all');
    }

    /**
     * A caller's max_tokens larger than the window leaves no budget, so
     * nothing is admitted; a smaller one admits the same section.
     */
    #[Test]
    public function aCallerReserveLargerThanTheWindowAdmitsNothing(): void
    {
        $manager  = new ContextWindowManager();
        $messages = [ChatMessage::system('Identity.'), ChatMessage::user('Write the note.')];
        $config   = $this->config(10000, 1000);

        self::assertTrue($manager->admitsSection($messages, 'Short section.', $config, new ChatOptions(maxTokens: 2000)));
        self::assertFalse($manager->admitsSection($messages, 'Short section.', $config, new ChatOptions(maxTokens: 12000)));
    }

    /**
     * The resolved model's window decides, not the configuration's: a
     * section that fits the configuration's large window is refused against
     * a resolved 4096, and admitted against a resolved large one.
     */
    #[Test]
    public function theResolvedModelsWindowDecides(): void
    {
        $manager  = new ContextWindowManager();
        $messages = [ChatMessage::system('Identity.'), ChatMessage::user('Write the note.')];
        $section  = str_repeat('word ', 2500);
        $small    = new Model();
        $small->setContextLength(4096);
        $small->setMaxOutputTokens(500);

        $large = new Model();
        $large->setContextLength(100000);
        $large->setMaxOutputTokens(500);

        self::assertTrue($manager->admitsSection($messages, $section, $this->config(100000, 500), null));
        self::assertFalse($manager->admitsSection($messages, $section, $this->config(100000, 500), null, [], null, $small));
        self::assertTrue($manager->admitsSection($messages, $section, $this->config(4096, 500), null, [], null, $large));
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
