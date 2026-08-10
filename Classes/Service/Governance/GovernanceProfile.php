<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Governance;

/**
 * A named set of expected governance values (ADR-145).
 *
 * A profile is a DEFINITION, not an engine. It states what a posture looks like
 * and nothing else: it enforces nothing, resolves nothing, and is never
 * consulted at runtime. The runtime keeps reading the same resolvers it always
 * did, and :php:`GovernanceProfileEvaluator` compares their answers against
 * these numbers afterwards.
 *
 * That separation is the whole point. A profile that could enforce would be a
 * second policy engine beside the one ADR-140's readout exists to make visible,
 * and two engines that can disagree are worse than one nobody can read.
 *
 * The values are JUDGEMENT, not derivation. They are chosen to describe a
 * recognisable posture an operator can aim at, not because a measurement said
 * 30 days was right. A deviation is therefore a question worth asking, never a
 * defect.
 *
 * @internal
 */
enum GovernanceProfile: string
{
    /**
     * Everything runs against providers on your own infrastructure. Content can
     * be kept in full because it never leaves the installation, and skills need
     * only ordinary provenance.
     */
    case LOCAL_ONLY = 'local-only';

    /**
     * External providers under contract. Content is stored redacted, retention
     * is short, and skills need to have been reviewed.
     */
    case CONTROLLED_CLOUD = 'controlled-cloud';

    /**
     * The tightest posture: metadata only, minimal retention, first-party
     * skills alone.
     */
    case ENTERPRISE_STRICT = 'enterprise-strict';

    /**
     * A development installation. Full content for debugging, short retention
     * because it is throwaway, and the data-class axis in observe so a
     * developer sees what would be blocked instead of being blocked.
     */
    case DEVELOPMENT = 'development';

    /**
     * The expected value per governance key, keyed exactly as
     * {@see EffectivePolicyRow::$key}.
     *
     * A key absent here is one this profile makes no statement about, and the
     * evaluator reports nothing for it. Silence is a legitimate position: a
     * profile that had an opinion on every key would force operators to
     * disagree with it about things it was never meant to describe.
     *
     * @return array<string, string>
     */
    public function expectations(): array
    {
        return match ($this) {
            self::LOCAL_ONLY => [
                'privacy.level'              => 'full',
                'privacy.retentionDays'      => '90',
                'tools.dataClassEnforcement' => 'enforce',
                'skills.minTrustLevel'       => 'community',
            ],
            self::CONTROLLED_CLOUD => [
                'privacy.level'              => 'redacted',
                'privacy.retentionDays'      => '30',
                'tools.dataClassEnforcement' => 'enforce',
                'skills.minTrustLevel'       => 'verified',
            ],
            self::ENTERPRISE_STRICT => [
                'privacy.level'              => 'metadata',
                'privacy.retentionDays'      => '7',
                'tools.dataClassEnforcement' => 'enforce',
                'skills.minTrustLevel'       => 'first_party',
            ],
            self::DEVELOPMENT => [
                'privacy.level'              => 'full',
                'privacy.retentionDays'      => '7',
                'tools.dataClassEnforcement' => 'observe',
                'skills.minTrustLevel'       => 'untrusted',
            ],
        };
    }

    public function labelKey(): string
    {
        return 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:governance.profile.' . $this->name;
    }

    public function descriptionKey(): string
    {
        return $this->labelKey() . '.description';
    }

    public static function fromValue(?string $value): ?self
    {
        return self::tryFrom(trim((string)$value));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }
}
