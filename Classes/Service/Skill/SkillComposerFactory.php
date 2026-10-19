<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\SkillTrustLevel;
use Throwable;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
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
 * The instruction threshold (ADR-214) is read from
 * ``skills.instructionTrustLevel``; see {@see self::instructionTrustLevel()}.
 *
 * A factory (rather than injecting {@see ExtensionConfiguration} into the
 * composer) keeps {@see SkillComposer} a pure, trivially constructable value
 * service for its many unit tests.
 */
final readonly class SkillComposerFactory
{
    /**
     * The instruction threshold when the setting is absent or empty
     * (ADR-214 item 2).
     */
    public const DEFAULT_INSTRUCTION_TRUST_LEVEL = SkillTrustLevel::VERIFIED;

    /**
     * @param SkillApprovalRepositoryInterface|null $approvals and
     * @param SkillSourceLookupInterface|null       $sources   are what the instruction policy (ADR-214) reads. Optional and
     *                                                         trailing so the lean constructions keep working; absent either,
     *                                                         the composer gets no policy and every skill keeps the fenced frame
     */
    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
        private ?SkillApprovalRepositoryInterface $approvals = null,
        private ?SkillSourceLookupInterface $sources = null,
        private ?SkillRecordLookupInterface $records = null,
    ) {}

    public function create(): SkillComposer
    {
        return new SkillComposer(
            maxBytes: $this->resolveMaxBytes(),
            minTrustLevel: $this->minTrustLevel(),
            instructionPolicy: $this->instructionPolicy(),
            sources: $this->sources,
        );
    }

    /**
     * The policy that decides which approved versions instruct, or null when
     * this factory was built without the stores it reads.
     */
    public function instructionPolicy(): ?SkillInstructionPolicy
    {
        if (!$this->approvals instanceof SkillApprovalRepositoryInterface || !$this->sources instanceof SkillSourceLookupInterface) {
            return null;
        }

        return new SkillInstructionPolicy($this->approvals, $this->sources, $this->instructionTrustLevel(), $this->records);
    }

    /**
     * The effective provenance level a skill's source must reach before an
     * approved version of it is composed as an instruction (ADR-214 item 2).
     *
     * Read from ``skills.instructionTrustLevel``. An absent or empty value is
     * the default, ``verified``, as is an extension that is not configured at
     * all. An unrecognised value, and a configuration that cannot be read,
     * fail CLOSED to the highest level, ``first_party``: this threshold grants a privilege, so a
     * typo must narrow it, never widen it — the opposite direction of
     * {@see self::minTrustLevel()}, which hides skills when raised. A value
     * below ``skills.minTrustLevel`` is read as ``skills.minTrustLevel``,
     * since a skill that is not admitted cannot instruct.
     *
     * Public so the read-only governance readout shows the value the runtime
     * applies.
     */
    public function instructionTrustLevel(): SkillTrustLevel
    {
        try {
            $skills = $this->skillsConfig();
            $raw   = $skills['instructionTrustLevel'] ?? null;
            $value = is_string($raw) ? trim($raw) : '';
            $level = match (true) {
                $raw === null, $value === '' && is_string($raw) => self::DEFAULT_INSTRUCTION_TRUST_LEVEL,
                // Present but not a string (an integer in settings.php, say):
                // unrecognised, so it fails closed like a typo.
                !is_string($raw) => SkillTrustLevel::FIRST_PARTY,
                default          => SkillTrustLevel::tryFrom($value) ?? SkillTrustLevel::FIRST_PARTY,
            };
        } catch (ExtensionConfigurationExtensionNotConfiguredException) {
            // Nothing configured at all (a fresh install): the default.
            $level = self::DEFAULT_INSTRUCTION_TRUST_LEVEL;
        } catch (Throwable) {
            // A configuration that exists but cannot be read is unreadable,
            // not absent: fail closed, like an unrecognised value.
            $level = SkillTrustLevel::FIRST_PARTY;
        }

        $minimum = $this->minTrustLevel();

        return $level->satisfies($minimum) ? $level : $minimum;
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
