<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\SkillTrustLevel;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Builds {@see SkillComposer} with the instance-configured minimum trust level
 * (ADR-061) and skill-block byte budget (ADR-036 §5).
 *
 * The floor is read from the extension configuration key
 * ``skills.minTrustLevel`` and fails CLOSED to {@see SkillTrustLevel::UNTRUSTED}
 * (the default — accept every enabled skill) when the value is missing,
 * unreadable or unrecognised, so a misconfigured instance never silently
 * *raises* the bar in a way that hides skills without an admin choosing to. An
 * admin who sets ``verified`` makes the composer drop every skill below that
 * level from both injection and the allowed-tools union.
 *
 * The budget is read from ``skills.maxBytes`` and falls back to
 * {@see SkillComposer::DEFAULT_MAX_BYTES} whenever the value is missing,
 * unreadable, non-numeric or non-positive. The fallback direction is the
 * opposite of the trust floor's on purpose: a bad trust value must not raise
 * the bar, a bad budget must not *remove* one. In particular ``0`` means "use
 * the default", never "uncapped" — that matches the extension's other numeric
 * settings (``rerankerTimeout``, ``privacy.retentionDays``) and keeps an
 * emptied field from silently letting an unbounded skill block onto the wire.
 * Lowering the value is the way to tighten the cap; there is no way to switch
 * it off.
 *
 * A factory (rather than injecting {@see ExtensionConfiguration} into the
 * composer) keeps {@see SkillComposer} a pure, trivially constructable value
 * service for its many unit tests.
 */
final readonly class SkillComposerFactory
{
    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function create(): SkillComposer
    {
        return new SkillComposer(
            maxBytes: $this->resolveMaxBytes(),
            minTrustLevel: $this->minTrustLevel(),
        );
    }

    /**
     * Instance-configured skill-block byte budget, or the composer's default
     * for any value that cannot be read as a positive integer.
     */
    private function resolveMaxBytes(): int
    {
        try {
            $value = $this->skillsConfig()['maxBytes'] ?? null;
        } catch (Throwable) {
            return SkillComposer::DEFAULT_MAX_BYTES;
        }

        $bytes = is_numeric($value) ? (int)$value : 0;

        return $bytes >= 1 ? $bytes : SkillComposer::DEFAULT_MAX_BYTES;
    }

    /**
     * The effective minimum publisher-trust level every composer is built with.
     *
     * Public so the read-only governance readout (ADR-140) can show the value
     * the runtime actually applies instead of re-reading and re-interpreting
     * ``skills.minTrustLevel`` itself, which would let the view drift from the
     * composer.
     */
    public function minTrustLevel(): SkillTrustLevel
    {
        try {
            $skills = $this->skillsConfig();
            $value  = is_string($skills['minTrustLevel'] ?? null) ? $skills['minTrustLevel'] : '';
        } catch (Throwable) {
            return SkillTrustLevel::UNTRUSTED;
        }

        return SkillTrustLevel::fromStringOrUntrusted($value);
    }

    /**
     * The ``skills.*`` sub-array of the extension configuration.
     *
     * Throws whatever {@see ExtensionConfiguration::get()} throws (an
     * unavailable or unreadable configuration); each caller catches it and
     * applies its own fallback.
     *
     * @return array<string, mixed>
     */
    private function skillsConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = $this->extensionConfiguration->get('nr_llm');

        /** @var array<string, mixed> $skills */
        $skills = is_array($config['skills'] ?? null) ? $config['skills'] : [];

        return $skills;
    }
}
