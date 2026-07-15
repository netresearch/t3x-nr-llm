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
 * The detection rules are pure *data* ({@see PATTERNS}) so they are auditable
 * and unit-testable in isolation, one assertion per signature. The tiering is
 * deliberately conservative to avoid over-blocking legitimate prose:
 *
 * - {@see InjectionSeverity::HIGH} is reserved for unambiguous jailbreak
 *   markers (instruction override, role reset, DAN/developer-mode personas,
 *   chat-template control tokens) that essentially never occur in a genuine
 *   ``SKILL.md``. A HIGH finding force-disables the skill at import.
 * - {@see InjectionSeverity::MEDIUM} / {@see InjectionSeverity::LOW} cover
 *   weaker signals (secret-exposure verbs, guardrail-bypass wording, covert
 *   behaviour, long encoded blobs). They only *flag* the record for review —
 *   they never block, because the repo/marketplace skills they most often
 *   appear on already arrive disabled.
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
     * Every pattern is case-insensitive and anchored on an imperative verb +
     * object so that descriptive prose ("follow these instructions",
     * "the system prompt is configured elsewhere") does not match.
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
            $normalised = mb_substr($normalised, 0, self::EXCERPT_MAX_CHARS) . '…';
        }

        return $normalised;
    }
}
