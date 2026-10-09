<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use InvalidArgumentException;
use Netresearch\NrLlm\Service\Tool\Builtin\AskChoiceTool;
use Netresearch\NrLlm\Service\Tool\InputSchema;
use Netresearch\NrLlm\Service\Tool\ToolApprovalRule;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AskChoiceTool::class)]
final class AskChoiceToolTest extends TestCase
{
    #[Test]
    public function theSchemaOfACallEnumeratesItsOptions(): void
    {
        $schema = (new AskChoiceTool())->inputSchemaFor(['question' => ' Which page? ', 'options' => [' Home ', 'About']]);

        self::assertTrue(InputSchema::isUsable($schema));
        self::assertSame([AskChoiceTool::ANSWER], $schema['required']);
        self::assertSame(['Home', 'About'], $schema['properties'][AskChoiceTool::ANSWER]['enum'] ?? null, 'options are trimmed like the answer is compared');
        self::assertSame('Which page?', $schema['properties'][AskChoiceTool::ANSWER]['title'] ?? null);
    }

    #[Test]
    public function argumentsItCannotAskWithAreRefused(): void
    {
        foreach ([
            'no question'          => ['options' => ['a', 'b']],
            'an empty question'    => ['question' => '  ', 'options' => ['a', 'b']],
            'a two-line question'  => ['question' => "Which\npage?", 'options' => ['a', 'b']],
            'a long question'      => ['question' => str_repeat('q', 301), 'options' => ['a', 'b']],
            'no options'           => ['question' => 'Q?'],
            'one option'           => ['question' => 'Q?', 'options' => ['a']],
            'eleven options'       => ['question' => 'Q?', 'options' => range('a', 'k')],
            'options as a map'     => ['question' => 'Q?', 'options' => ['x' => 'a', 'y' => 'b']],
            'a non-string option'  => ['question' => 'Q?', 'options' => ['a', 2]],
            'an empty option'      => ['question' => 'Q?', 'options' => ['a', ' ']],
            'a long option'        => ['question' => 'Q?', 'options' => ['a', str_repeat('o', 121)]],
            'a two-line option'    => ['question' => 'Q?', 'options' => ['a', "b\nc"]],
            'duplicate options'    => ['question' => 'Q?', 'options' => ['a', ' a']],
            'a zero-width twin'    => ['question' => 'Q?', 'options' => ['Home', "Ho\u{200B}me"]],
            'a direction mark'     => ['question' => 'Q?', 'options' => ['a', "\u{202E}b"]],
            'a tab'                => ['question' => "Which\tpage?", 'options' => ['a', 'b']],
            'a line separator'     => ['question' => 'Q?', 'options' => ['a', "b\u{2028}c"]],
            'a no-break twin'      => ['question' => 'Q?', 'options' => ['10 %', "10\u{00A0}%"]],
            'a soft-hyphen twin'   => ['question' => 'Q?', 'options' => ['Seite', "Sei\u{00AD}te"]],
            'a decomposed twin'    => ['question' => 'Q?', 'options' => ["caf\u{00E9}", "cafe\u{0301}"]],
            'invalid UTF-8'        => ['question' => 'Q?', 'options' => ['a', "\xC3"]],
        ] as $case => $arguments) {
            try {
                (new AskChoiceTool())->inputSchemaFor($arguments);
                self::fail('Accepted ' . $case);
            } catch (InvalidArgumentException $e) {
                self::assertStringStartsWith('ask_choice needs', $e->getMessage(), $case);
            }

            $result = (new AskChoiceTool())->execute($arguments, ToolExecutionContext::none());
            self::assertTrue($result->isError, $case . ': execute refuses the same arguments');
        }
    }

    /**
     * Typographic spaces a model writes in German are spaces, and the error
     * for an invisible character names it instead of blaming the length.
     */
    #[Test]
    public function typographicSpacesAreSpacesAndAnInvisibleCharacterIsNamed(): void
    {
        $schema = (new AskChoiceTool())->inputSchemaFor([
            'question' => "Welche Gr\u{00F6}\u{00DF}e,\u{202F}z.\u{00A0}B.?",
            'options'  => ["10\u{00A0}%", "20\u{2009}%", "Ab\u{00AD}schnitt", "\u{3000}Gr\u{00F6}\u{00DF}e \u{00E4}ndern \u{2014} \u{20AC}"],
        ]);

        self::assertSame("Welche Gr\u{00F6}\u{00DF}e, z. B.?", $schema['properties'][AskChoiceTool::ANSWER]['title'] ?? null);
        self::assertSame(['10 %', '20 %', 'Abschnitt', "Gr\u{00F6}\u{00DF}e \u{00E4}ndern \u{2014} \u{20AC}"], $schema['properties'][AskChoiceTool::ANSWER]['enum'] ?? null);

        foreach ([
            'a zero-width space' => ['question' => 'Q?', 'options' => ['a', "b\u{200B}"]],
            'an emoji joiner'    => ['question' => "Wer\u{200D}?", 'options' => ['a', 'b']],
        ] as $case => $arguments) {
            try {
                (new AskChoiceTool())->inputSchemaFor($arguments);
                self::fail('Accepted ' . $case);
            } catch (InvalidArgumentException $e) {
                self::assertSame(1791603005, $e->getCode(), $case);
                self::assertStringContainsString('without invisible characters', $e->getMessage(), $case);
                self::assertMatchesRegularExpression('/U\+200[BD]\.$/', $e->getMessage(), $case);
            }
        }
    }

    /**
     * A line break is blamed as one, not as an invisible character: the
     * model is told the text must be one line.
     */
    #[Test]
    public function aLineBreakIsRefusedAsMoreThanOneLine(): void
    {
        foreach ([
            'a two-line question' => [['question' => "Which\npage?", 'options' => ['a', 'b']], 1791603001],
            'a tab'               => [['question' => "Which\tpage?", 'options' => ['a', 'b']], 1791603001],
            'a two-line option'   => [['question' => 'Q?', 'options' => ['a', "b\r\nc"]], 1791603003],
            'a line separator'    => [['question' => 'Q?', 'options' => ['a', "b\u{2028}c"]], 1791603003],
        ] as $case => [$arguments, $code]) {
            try {
                (new AskChoiceTool())->inputSchemaFor($arguments);
                self::fail('Accepted ' . $case);
            } catch (InvalidArgumentException $e) {
                self::assertSame($code, $e->getCode(), $case);
            }
        }
    }

    #[Test]
    public function itReportsOnlyAnAnswerThatIsOneOfTheOptions(): void
    {
        $call = ['question' => 'Which page?', 'options' => ['Home', 'About']];
        $tool = new AskChoiceTool();

        $picked = $tool->execute([...$call, AskChoiceTool::ANSWER => 'About'], ToolExecutionContext::none());
        self::assertFalse($picked->isError);
        self::assertSame('The user picked: About', $picked->content);

        foreach (['no answer' => [], 'an answer outside the options' => [AskChoiceTool::ANSWER => 'Contact'], 'a non-string' => [AskChoiceTool::ANSWER => 1]] as $case => $answer) {
            self::assertTrue($tool->execute([...$call, ...$answer], ToolExecutionContext::none())->isError, $case);
        }
    }

    /**
     * It is no write and needs no approval, so the ADR-134 ban on a pause that
     * collects both does not apply to it.
     */
    #[Test]
    public function itIsAPlainReadThatNeedsNoApproval(): void
    {
        $tool = new AskChoiceTool();

        self::assertNotInstanceOf(ToolEffectInterface::class, $tool);
        self::assertFalse(ToolApprovalRule::requiresApproval($tool));
        self::assertFalse($tool->requiresAdmin());
        self::assertFalse($tool->isEnabledByDefault(), 'a client must be able to answer an input pause before it is offered');
        self::assertTrue(InputSchema::isUsable($tool->getInputSchema()));
    }
}
