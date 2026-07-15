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
 * (ADR-061).
 *
 * The floor is read from the extension configuration key
 * ``skills.minTrustLevel`` and fails CLOSED to {@see SkillTrustLevel::UNTRUSTED}
 * (the default — accept every enabled skill) when the value is missing,
 * unreadable or unrecognised, so a misconfigured instance never silently
 * *raises* the bar in a way that hides skills without an admin choosing to. An
 * admin who sets ``verified`` makes the composer drop every skill below that
 * level from both injection and the allowed-tools union.
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
        return new SkillComposer(minTrustLevel: $this->resolveMinTrustLevel());
    }

    private function resolveMinTrustLevel(): SkillTrustLevel
    {
        try {
            /** @var array<string, mixed> $config */
            $config = $this->extensionConfiguration->get('nr_llm');
            $skills = is_array($config['skills'] ?? null) ? $config['skills'] : [];
            $value  = is_string($skills['minTrustLevel'] ?? null) ? $skills['minTrustLevel'] : '';
        } catch (Throwable) {
            return SkillTrustLevel::UNTRUSTED;
        }

        return SkillTrustLevel::fromStringOrUntrusted($value);
    }
}
