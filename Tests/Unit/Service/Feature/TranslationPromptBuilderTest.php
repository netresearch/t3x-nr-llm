<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Feature;

use Netresearch\NrLlm\Service\Feature\TranslationPromptBuilder;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(TranslationPromptBuilder::class)]
class TranslationPromptBuilderTest extends AbstractUnitTestCase
{
    private TranslationPromptBuilder $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new TranslationPromptBuilder();
    }

    /**
     * Invoke build() with a fully-controlled raw options array and return
     * the ['system', 'user'] strings.
     *
     * @param array<string, mixed> $options
     *
     * @return array{system: string, user: string}
     */
    private function invokeBuildPrompt(string $text, string $source, string $target, array $options): array
    {
        $prompt = $this->subject->build($text, $source, $target, $options);

        $system = $prompt['system'] ?? null;
        $user = $prompt['user'] ?? null;
        self::assertIsString($system);
        self::assertIsString($user);

        return ['system' => $system, 'user' => $user];
    }

    #[Test]
    public function buildTranslationPromptRendersMinimalPrompt(): void
    {
        $prompt = $this->invokeBuildPrompt('Hello World', 'en', 'de', [
            'formality' => 'default',
            'domain' => 'general',
            'glossary' => [],
            'context' => '',
            'preserve_formatting' => true,
        ]);

        $system = $prompt['system'];
        // Domain + language-name resolution (en -> English, de -> German).
        self::assertStringContainsString(
            'You are a professional general translator. Translate the following text from English to German.',
            $system,
        );
        // formality 'default' => no tone line.
        self::assertStringNotContainsString('Maintain', $system);
        // preserve_formatting true => formatting line present.
        self::assertStringContainsString(
            'Preserve all formatting, HTML tags, markdown, and special characters.',
            $system,
        );
        // Empty glossary / context => their sections are absent.
        self::assertStringNotContainsString('Use these exact term translations:', $system);
        self::assertStringNotContainsString('Context (for reference only):', $system);
        // Trailing instruction is appended, not replacing the prompt.
        self::assertStringContainsString('Provide ONLY the translation, no explanations or notes.', $system);

        self::assertSame("Translate this text:\n\nHello World", $prompt['user']);
    }

    #[Test]
    public function buildTranslationPromptDefaultsPreserveFormattingToTrueWhenKeyAbsent(): void
    {
        // No 'preserve_formatting' key => the `?? true` default applies.
        $prompt = $this->invokeBuildPrompt('Hello', 'en', 'de', [
            'formality' => 'default',
            'domain' => 'general',
        ]);

        self::assertStringContainsString(
            'Preserve all formatting, HTML tags, markdown, and special characters.',
            $prompt['system'],
        );
    }

    #[Test]
    public function buildTranslationPromptOmitsFormattingLineWhenPreserveFormattingFalse(): void
    {
        $prompt = $this->invokeBuildPrompt('Hello', 'en', 'de', [
            'formality' => 'default',
            'domain' => 'general',
            'preserve_formatting' => false,
        ]);

        self::assertStringNotContainsString('Preserve all formatting', $prompt['system']);
    }

    #[Test]
    public function buildTranslationPromptRendersAllSectionsWithFullOptions(): void
    {
        $prompt = $this->invokeBuildPrompt('Hello', 'en', 'de', [
            'formality' => 'formal',
            'domain' => 'legal',
            'glossary' => [
                'Hello' => 'Hallo',   // string value
                'count' => 5,         // int value
                'ratio' => 1.5,       // float value
                'bad' => ['x'],       // non-scalar => filtered out
            ],
            'context' => 'Software docs',
            'preserve_formatting' => false,
        ]);

        $system = $prompt['system'];

        // Intro must survive every section append (guards against `.=` -> `=`).
        self::assertStringContainsString(
            'You are a professional legal translator. Translate the following text from English to German.',
            $system,
        );
        // formality 'formal' => tone line.
        self::assertStringContainsString('Maintain formal tone.', $system);
        // preserve false => no formatting line.
        self::assertStringNotContainsString('Preserve all formatting', $system);
        // Glossary header + one line per scalar term.
        self::assertStringContainsString('Use these exact term translations:', $system);
        self::assertStringContainsString('- Hello → Hallo', $system);
        self::assertStringContainsString('- count → 5', $system);
        self::assertStringContainsString('- ratio → 1.5', $system);
        // Non-scalar glossary value is filtered out entirely.
        self::assertStringNotContainsString('- bad', $system);
        // Context section rendered.
        self::assertStringContainsString("Context (for reference only):\nSoftware docs", $system);
        // Closing instruction still appended.
        self::assertStringContainsString('Provide ONLY the translation, no explanations or notes.', $system);
    }

    #[Test]
    public function buildTranslationPromptResolvesKnownLanguageNames(): void
    {
        // getLanguageName maps known codes; an unknown code falls back to itself.
        $prompt = $this->invokeBuildPrompt('Hi', 'fr', 'zz', [
            'formality' => 'default',
            'domain' => 'general',
        ]);

        self::assertStringContainsString('from French to zz.', $prompt['system']);
    }
}
