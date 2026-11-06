<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ExceptionHintAdmission;
use Netresearch\NrLlm\Domain\Enum\ExceptionHintRefusal;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ExceptionHint;
use Netresearch\NrLlm\Domain\ValueObject\LogExceptionEntry;
use Netresearch\NrLlm\Provider\Middleware\CacheMiddleware;
use Netresearch\NrLlm\Provider\Middleware\TelemetryMiddleware;
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

    /** Characters of the message sent; a logged message has no length limit. */
    private const MAX_MESSAGE = 4000;

    /**
     * Never from the answer cache: a cached unreadable answer would refuse
     * every retry for the cache's lifetime. Attributed in the usage data.
     */
    private const METADATA = [
        CacheMiddleware::METADATA_ANSWER_CACHE         => false,
        TelemetryMiddleware::METADATA_SOURCE_EXTENSION => 'nr_llm',
        TelemetryMiddleware::METADATA_SOURCE_OPERATION => 'exception_hint',
    ];

    /**
     * Not the project's own code although inside its root: packages, core in
     * a classic-mode installation, and generated files (compiled templates,
     * caches), which `SourcePathGuard` does not read either.
     */
    private const NOT_PROJECT_CODE = ['vendor/', 'typo3/sysext/', 'var/', 'typo3temp/'];

    private const SYSTEM_PROMPT = <<<'PROMPT'
        You help a TYPO3 administrator understand one exception from the TYPO3 log.
        You get the exception class, possibly its message, and the stack frames, numbered from #0 (the throw site).
        Answer with one JSON object and nothing else:
        {"cause": "<the likely cause, one or two sentences>", "next_step": "<the one thing to check or change first>", "frame": <the number of the frame the cause sits in, or null>}
        Name a frame only if the frames themselves show it. Do not invent file contents, configuration values or credentials.
        Write the values of "cause" and "next_step" in %s; keep the JSON keys as they are.
        PROMPT;

    public function __construct(
        private LlmServiceManagerInterface $llm,
        private TrustZoneResolver $trustZones,
        private SourcePathGuard $guard,
    ) {}

    /**
     * What a hint against this configuration may send: the trust zone the
     * configuration can reach, fallback hops included, on the data-class
     * ladder. {@see self::hint()} applies it; the backend module shows it next
     * to each configuration it offers.
     */
    public function admission(LlmConfiguration $configuration): ExceptionHintAdmission
    {
        $ceiling = $this->trustZones->ceilingFor($configuration);

        return match (true) {
            ToolDataClass::SECRET_ADJACENT->isAtMost($ceiling)    => ExceptionHintAdmission::FULL,
            ToolDataClass::SYSTEM_DIAGNOSTICS->isAtMost($ceiling) => ExceptionHintAdmission::WITHOUT_MESSAGE,
            default                                               => ExceptionHintAdmission::NONE,
        };
    }

    /**
     * @param string $language the language the cause and next step are
     *                         written in, as an English name ("German")
     */
    public function hint(LogExceptionEntry $entry, LlmConfiguration $configuration, string $language = 'English'): ExceptionHint
    {
        $admission = $this->admission($configuration);
        if ($admission === ExceptionHintAdmission::NONE) {
            return ExceptionHint::refused(ExceptionHintRefusal::ZONE_ADMITS_NO_DIAGNOSTICS, $configuration->getIdentifier());
        }

        $withMessage = $admission === ExceptionHintAdmission::FULL;
        $frames      = array_slice($entry->frames, 0, self::MAX_FRAMES);
        if (!$withMessage && $entry->exceptionClass === null && $frames === []) {
            return ExceptionHint::refused(ExceptionHintRefusal::NOTHING_TO_SEND);
        }

        try {
            $response = $this->llm->chatWithConfiguration(
                [ChatMessage::system(sprintf(self::SYSTEM_PROMPT, $language)), ChatMessage::user($this->describe($entry, $frames, $withMessage))],
                $configuration,
                self::METADATA,
            );
        } catch (Throwable $exception) {
            return ExceptionHint::refused(ExceptionHintRefusal::CALL_FAILED, $this->sanitizeErrorMessage($exception->getMessage()));
        }

        $answer = $this->decode($response->content);
        if ($answer === null) {
            return ExceptionHint::refused(ExceptionHintRefusal::UNREADABLE_ANSWER);
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
            ? 'Message: ' . mb_substr($this->sanitizeErrorMessage($entry->message), 0, self::MAX_MESSAGE)
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
     * Inside the project root, and neither under vendor/ nor, in a classic-mode
     * installation, under typo3/sysext/.
     */
    private function isProjectFrame(string $file): bool
    {
        $root = $this->guard->rootPath();
        // A path with dot segments can point anywhere; it is not taken as the project's.
        if ($root === null || !str_starts_with($file, $root . '/') || preg_match('#/\.\.?(/|$)#', $file) === 1) {
            return false;
        }

        $relative = substr($file, strlen($root) + 1);
        foreach (self::NOT_PROJECT_CODE as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return false;
            }
        }

        return true;
    }
}
