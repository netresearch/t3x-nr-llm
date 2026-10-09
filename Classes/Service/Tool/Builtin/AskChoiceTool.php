<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use InvalidArgumentException;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Skill\SkillInvisibleCharacters;
use Netresearch\NrLlm\Service\Tool\ArgumentInputSchemaInterface;
use Netresearch\NrLlm\Service\Tool\RequiresInputInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;

/**
 * Ask the person one question with a fixed set of answers (ADR-214 item 9).
 *
 * The pure choice of a guided process — which page, which finding first,
 * whether to continue — as a WAITING_FOR_INPUT suspension (ADR-105). The
 * answers are the call's own `options`, so the input schema is built per call
 * ({@see ArgumentInputSchemaInterface}) as one required string property with
 * those options as its `enum`; the submission is refused unless it is one of
 * them. The result names the answer and nothing else.
 *
 * It declares no write effect, so the ADR-134 ban on an approval that also
 * collects input does not apply, and it never stands in for the approval of
 * a write: a write is a call of its own and gets its own card.
 *
 * Security contract (see {@see ToolInterface}): reads nothing and writes
 * nothing; what egresses is the question the model asked and the answer the
 * person picked. Not admin-only. Every option is a bounded single line, so a
 * call cannot turn the form into a page of model-written text.
 */
final readonly class AskChoiceTool implements ToolInterface, RequiresInputInterface, ArgumentInputSchemaInterface
{
    public const NAME = 'ask_choice';

    /** The property the answer arrives in. */
    public const ANSWER = 'answer';

    private const MIN_OPTIONS = 2;

    private const MAX_OPTIONS = 10;

    private const MAX_OPTION_LENGTH = 120;

    private const MAX_QUESTION_LENGTH = 300;

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'ask_choice',
            sprintf(
                'Ask the user one question and wait for them to pick one of the given answers. Use it for a choice that writes nothing (which page, which point first, whether to continue); a change is proposed with the tool that makes it. Give %d to %d short, distinct answers.',
                self::MIN_OPTIONS,
                self::MAX_OPTIONS,
            ),
            [
                'type'       => 'object',
                'properties' => [
                    'question' => [
                        'type'        => 'string',
                        'description' => sprintf('The question, one line, at most %d characters.', self::MAX_QUESTION_LENGTH),
                    ],
                    'options' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => sprintf('The answers to pick from, %d to %d, each one line of at most %d characters.', self::MIN_OPTIONS, self::MAX_OPTIONS, self::MAX_OPTION_LENGTH),
                    ],
                ],
                'required' => ['question', 'options'],
            ],
        );
    }

    /**
     * The general shape, without the options of a call: one string answer.
     */
    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [self::ANSWER => ['type' => 'string']],
            'required'   => [self::ANSWER],
        ];
    }

    public function inputSchemaFor(array $arguments): array
    {
        [$question, $options] = $this->askable($arguments);

        return [
            'type'       => 'object',
            'properties' => [
                self::ANSWER => [
                    'type'        => 'string',
                    'title'       => $question,
                    'enum'        => $options,
                ],
            ],
            'required' => [self::ANSWER],
        ];
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        try {
            [, $options] = $this->askable($arguments);
        } catch (InvalidArgumentException $e) {
            return ToolResult::error(sprintf('Error: %s', $e->getMessage()));
        }

        // The loop overlays the person's answer onto the arguments after the
        // suspension. A call that reaches execute without one was never
        // suspended on, and an answer the model wrote itself is not the
        // person's: both are refused rather than reported as a choice.
        $answer = $arguments[self::ANSWER] ?? null;
        if (!is_string($answer) || !in_array($answer, $options, true)) {
            return ToolResult::error('Error: no answer was given; ask_choice reports only an answer the user picked.');
        }

        return ToolResult::text(sprintf('The user picked: %s', $answer));
    }

    public function isEnabledByDefault(): bool
    {
        // Disabled until enabled deliberately: a client that cannot answer a
        // run that waits for input — the chat before it learned to — would
        // see such a run fail, and every chat is offered the enabled set.
        return false;
    }

    public function requiresAdmin(): bool
    {
        return false;
    }

    public function getGroup(): string
    {
        return 'content';
    }

    /**
     * The question and the options of a call, or an exception naming what is
     * wrong with them.
     *
     * @param array<string, mixed> $arguments
     *
     * @throws InvalidArgumentException
     *
     * @return array{0: string, 1: non-empty-list<string>}
     */
    private function askable(array $arguments): array
    {
        $question = $arguments['question'] ?? null;
        $question = is_string($question) ? $this->normalised($question) : '';
        if (!$this->isOneLine($question, self::MAX_QUESTION_LENGTH)) {
            throw new InvalidArgumentException(sprintf('ask_choice needs a question of one line and at most %d characters.', self::MAX_QUESTION_LENGTH), 1791603001);
        }

        $this->refuseInvisible($question, 'the question');

        $options = $arguments['options'] ?? null;
        if (!is_array($options) || !array_is_list($options) || count($options) < self::MIN_OPTIONS || count($options) > self::MAX_OPTIONS) {
            throw new InvalidArgumentException(sprintf('ask_choice needs %d to %d options.', self::MIN_OPTIONS, self::MAX_OPTIONS), 1791603002);
        }

        $answers = [];
        foreach ($options as $option) {
            $option = is_string($option) ? $this->normalised($option) : '';
            if (!$this->isOneLine($option, self::MAX_OPTION_LENGTH)) {
                throw new InvalidArgumentException(sprintf('ask_choice needs every option as one line of at most %d characters.', self::MAX_OPTION_LENGTH), 1791603003);
            }

            $this->refuseInvisible($option, 'an option');
            $answers[] = $option;
        }

        if (count(array_unique($answers)) !== count($answers)) {
            throw new InvalidArgumentException('ask_choice needs distinct options.', 1791603004);
        }

        return [$question, $answers];
    }

    /**
     * Every space separator becomes a plain space and a soft hyphen is
     * dropped, then the text is trimmed. A model writes "10 %" or "z. B."
     * with a no-break space as German typography asks; on the form it reads
     * as a space, so it is one, and "10 %" with either space is the same
     * option to the distinctness check. Text that is not valid UTF-8 becomes
     * empty and is refused as no line at all.
     */
    private function normalised(string $text): string
    {
        $spaced = preg_replace(['/\x{00AD}/u', '/\p{Zs}/u'], ['', ' '], $text);

        return is_string($spaced) ? trim($spaced) : '';
    }

    private function isOneLine(string $text, int $maxLength): bool
    {
        return $text !== ''
            && mb_strlen($text) <= $maxLength
            && preg_match('/[\t\n\v\f\r\x{85}\x{2028}\x{2029}]/u', $text) === 0;
    }

    /**
     * No control, format or other invisible character: two options that
     * differ only by a zero-width or direction mark would look alike on the
     * form and still be different answers. The message names the character,
     * so the model can leave it out; an emoji joined by U+200D or styled by
     * U+FE0F is refused this way too, and asking with it unjoined works.
     *
     * @throws InvalidArgumentException
     */
    private function refuseInvisible(string $text, string $where): void
    {
        if (preg_match(SkillInvisibleCharacters::PATTERN, $text, $match) === 1) {
            throw new InvalidArgumentException(sprintf('ask_choice needs %s without invisible characters; leave out U+%04X.', $where, mb_ord($match[0], 'UTF-8')), 1791603005);
        }
    }
}
