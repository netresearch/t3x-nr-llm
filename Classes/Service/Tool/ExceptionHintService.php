<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ExceptionHint;
use Netresearch\NrLlm\Domain\ValueObject\LogExceptionEntry;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use Throwable;

/**
 * Asks a model for the likely cause and next step of one logged exception
 * (ADR-258). Used by `nrllm:exception:hint` and the administrators' error
 * list; never by anything an editor or a visitor reaches.
 *
 * What the model receives is less than what an administrator already sees for
 * the entry: the exception class, the message after the credential sanitizer,
 * and each frame as file, line and function name. Call arguments are cut from
 * the frames (PHP writes argument values into a trace unless
 * `zend.exception_ignore_args` is on) and no source line is sent.
 *
 * The configuration's trust zone decides what leaves the house, by the same
 * data-class ladder the tool loop applies (ADR-094): the class and the frames
 * are system diagnostics, the message sits next to secrets. A zone below
 * {@see ToolDataClass::SYSTEM_DIAGNOSTICS} is refused without a call; a zone
 * below {@see ToolDataClass::SECRET_ADJACENT} gets the entry without its
 * message, and the hint says so.
 *
 * Whether a hint is a guess is decided here, not by the model: only a hint
 * that cites a frame of the entry's own stack lying in the project's code is
 * anchored; everything else is labelled a guess.
 */
final readonly class ExceptionHintService
{
    use ErrorMessageSanitizerTrait;

    private const MAX_FRAMES = 20;

    private const SYSTEM_PROMPT = <<<'PROMPT'
        You help a TYPO3 administrator understand one exception from the TYPO3 log.
        You get the exception class, possibly its message, and the stack frames, numbered from #0 (the throw site).
        Answer with one JSON object and nothing else:
        {"cause": "<the likely cause, one or two sentences>", "next_step": "<the one thing to check or change first>", "frame": <the number of the frame the cause sits in, or null>}
        Name a frame only if the frames themselves show it. Do not invent file contents, configuration values or credentials.
        Answer in English.
        PROMPT;

    public function __construct(
        private LlmServiceManagerInterface $llm,
        private TrustZoneResolver $trustZones,
        private SourcePathGuard $guard,
    ) {}

    public function hint(LogExceptionEntry $entry, LlmConfiguration $configuration): ExceptionHint
    {
        $ceiling = $this->trustZones->ceilingFor($configuration);
        if (!ToolDataClass::SYSTEM_DIAGNOSTICS->isAtMost($ceiling)) {
            return ExceptionHint::refused(sprintf(
                'The configuration "%s" reaches a provider whose trust zone admits no system diagnostics; no model was asked.',
                $configuration->getIdentifier(),
            ));
        }

        $withMessage = ToolDataClass::SECRET_ADJACENT->isAtMost($ceiling);
        $frames      = array_slice($entry->frames, 0, self::MAX_FRAMES);

        try {
            $response = $this->llm->chatWithConfiguration(
                [ChatMessage::system(self::SYSTEM_PROMPT), ChatMessage::user($this->describe($entry, $frames, $withMessage))],
                $configuration,
                ['source' => 'exception_hint'],
            );
        } catch (Throwable $exception) {
            return ExceptionHint::refused('The model call failed: ' . $this->sanitizeErrorMessage($exception->getMessage()));
        }

        $answer = $this->decode($response->content);
        if ($answer === null) {
            return ExceptionHint::refused('The model did not answer in the expected form.');
        }

        $frame = $answer['frame'];
        if ($frame !== null && !isset($frames[$frame])) {
            $frame = null;
        }

        $anchored = $frame !== null && $this->isProjectFrame($frames[$frame]['file']);

        return ExceptionHint::answered($answer['cause'], $answer['next_step'], $frame, !$anchored, !$withMessage);
    }

    /**
     * The entry as the model reads it.
     *
     * @param list<array{file: string, line: int, call: string}> $frames
     */
    public function describe(LogExceptionEntry $entry, array $frames, bool $withMessage): string
    {
        $lines   = [];
        $lines[] = 'Exception class: ' . ($entry->exceptionClass ?? '(not recorded)');
        $lines[] = $withMessage
            ? 'Message: ' . $this->sanitizeErrorMessage($entry->message)
            : 'Message: (withheld: the provider may not receive exception messages)';
        $lines[] = 'Component: ' . $entry->component;
        $lines[] = 'Frames:';
        foreach ($frames as $i => $frame) {
            $lines[] = sprintf('#%d %s:%d %s', $i, $frame['file'], $frame['line'], self::withoutArguments($frame['call']));
        }

        if ($frames === []) {
            $lines[] = '(no stack trace recorded)';
        }

        return implode("\n", $lines);
    }

    /**
     * `Foo->bar('secret', Object(X))` becomes `Foo->bar()`.
     */
    public static function withoutArguments(string $call): string
    {
        $open = strpos($call, '(');

        return $open === false ? $call : substr($call, 0, $open) . '()';
    }

    /**
     * @return array{cause: string, next_step: string, frame: int|null}|null
     */
    private function decode(string $content): ?array
    {
        $start = strpos($content, '{');
        $end   = strrpos($content, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $data = json_decode(substr($content, $start, $end - $start + 1), true);
        if (!is_array($data)) {
            return null;
        }

        $cause = $data['cause'] ?? null;
        $next  = $data['next_step'] ?? null;
        if (!is_string($cause) || trim($cause) === '' || !is_string($next) || trim($next) === '') {
            return null;
        }

        $frame = $data['frame'] ?? null;

        return ['cause' => trim($cause), 'next_step' => trim($next), 'frame' => is_int($frame) ? $frame : null];
    }

    /**
     * Inside the project root and not under vendor/, as `get_last_exception`
     * counts it.
     */
    private function isProjectFrame(string $file): bool
    {
        $root = $this->guard->rootPath();
        if ($root === null || !str_starts_with($file, $root . '/')) {
            return false;
        }

        return !str_starts_with(substr($file, strlen($root) + 1), 'vendor/');
    }
}
