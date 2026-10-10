<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\InjectionSeverity;
use Netresearch\NrLlm\Domain\ValueObject\InjectionFinding;
use Netresearch\NrLlm\Domain\ValueObject\InjectionScanResult;

/**
 * Scans a skill body for known prompt-injection signatures at ingest (ADR-061).
 *
 * The detection rules are pure data ({@see PATTERNS}) and return one finding
 * for each matched rule. They are heuristics: a match can also occur in quoted
 * examples, and a clean result does not establish that the body is trustworthy.
 *
 * - {@see InjectionSeverity::HIGH} marks instruction overrides, role resets,
 *   jailbreak personas and chat-template control tokens. The ingest path
 *   force-disables a skill with a HIGH finding.
 * - {@see InjectionSeverity::MEDIUM} / {@see InjectionSeverity::LOW} mark
 *   weaker signatures such as secret-exposure wording, guardrail bypass,
 *   covert behaviour and long encoded blobs. They flag the record for review
 *   without independently blocking its import.
 *
 * The scanner reads and returns; it never mutates a skill. The ingest path
 * ({@see SkillSyncService}) applies the force-disable and records the result.
 */
final class PromptInjectionScanner
{
    private const EXCERPT_MAX_CHARS = 120;

    /**
     * Ordered detection rules: label, severity, PCRE pattern.
     *
     * Wording and chat-template rules use case-insensitive matching. Several
     * wording rules combine a verb with a target; template control tokens and
     * long encoded blobs are detected independently of imperative wording.
     *
     * @var list<array{label: string, severity: InjectionSeverity, pattern: string}>
     */
    private const PATTERNS = [
        [
            'label'    => 'instruction-override',
            'severity' => InjectionSeverity::HIGH,
            'pattern'  => '/\b(?:ignore|disregard|forget)\b[^.\n]{0,40}\b(?:previous|prior|above|earlier|all)\b[^.\n]{0,25}\b(?:instruction|instructions|prompt|prompts|directive|directives|context)\b/i',
        ],
        [
            'label'    => 'role-override',
            'severity' => InjectionSeverity::HIGH,
            'pattern'  => '/\byou\s+are\s+now\s+(?:a|an|the|in|going|no\s+longer)\b/i',
        ],
        [
            'label'    => 'jailbreak-persona',
            'severity' => InjectionSeverity::HIGH,
            'pattern'  => '/\b(?:act\s+as|pretend\s+(?:to\s+be|you\s+are)|enable|enter|switch\s+to)\b[^.\n]{0,30}\b(?:dan|do\s+anything\s+now|developer\s+mode|jailbroken|jailbreak|unrestricted|unfiltered|no\s+restrictions)\b/i',
        ],
        [
            'label'    => 'chat-template-injection',
            'severity' => InjectionSeverity::HIGH,
            'pattern'  => '/<\|(?:im_start|im_end|system|user|assistant|endoftext)\|>|\[\/?INST\]/i',
        ],
        [
            'label'    => 'secret-exposure',
            'severity' => InjectionSeverity::MEDIUM,
            'pattern'  => '/\b(?:send|post|upload|transmit|exfiltrate|email|forward|leak|reveal|disclose|print|dump|expose)\b[^.\n]{0,40}\b(?:api[\s_-]?keys?|secrets?|tokens?|passwords?|credentials?|private\s+keys?)\b/i',
        ],
        [
            'label'    => 'system-prompt-probe',
            'severity' => InjectionSeverity::MEDIUM,
            'pattern'  => '/\b(?:reveal|show|print|repeat|output|disclose|reproduce)\b[^.\n]{0,30}\b(?:system\s+prompt|initial\s+instructions|your\s+(?:instructions|prompt|rules))\b/i',
        ],
        [
            'label'    => 'guardrail-bypass',
            'severity' => InjectionSeverity::MEDIUM,
            'pattern'  => '/\b(?:bypass|disable|turn\s+off|override|ignore)\b[^.\n]{0,25}\b(?:safety|guardrails?|filters?|restrictions?|content\s+(?:policy|filter)|moderation)\b/i',
        ],
        [
            'label'    => 'covert-behavior',
            'severity' => InjectionSeverity::MEDIUM,
            'pattern'  => '/\b(?:do\s+not|don\'t|never|without)\b[^.\n]{0,25}\b(?:tell|telling|inform|informing|notify|notifying|mention|mentioning|alert)\b[^.\n]{0,20}\b(?:the\s+)?(?:user|admin|operator|human)\b/i',
        ],
        [
            'label'    => 'encoded-payload',
            'severity' => InjectionSeverity::LOW,
            'pattern'  => '/[A-Za-z0-9+\/]{200,}={0,2}/',
        ],
    ];

    public function scan(string $body): InjectionScanResult
    {
        if (trim($body) === '') {
            return new InjectionScanResult();
        }

        $findings = [];
        foreach (self::PATTERNS as $rule) {
            if (preg_match($rule['pattern'], $body, $matches) === 1) {
                $findings[] = new InjectionFinding(
                    $rule['label'],
                    $rule['severity'],
                    $this->excerpt($matches[0]),
                );
            }
        }

        return new InjectionScanResult($findings);
    }

    /**
     * Collapse whitespace and cap the matched slice so the audit trail stores a
     * short, readable marker rather than an unbounded body fragment.
     */
    private function excerpt(string $match): string
    {
        $normalised = trim((string)preg_replace('/\s+/', ' ', $match));
        if (mb_strlen($normalised) > self::EXCERPT_MAX_CHARS) {
            return mb_substr($normalised, 0, self::EXCERPT_MAX_CHARS) . '…';
        }

        return $normalised;
    }
}
