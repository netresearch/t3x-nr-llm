<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\SkillAuditEvent;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\SkillSource;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Writes the append-only skill audit trail (ADR-061).
 *
 * Every ingest outcome, enable/disable and fail-closed rejection is recorded
 * with who (the acting backend user), when, from which source/commit, at which
 * body checksum, at which trust level, and with the injection-scan result. The
 * service only ever appends — it wraps {@see SkillAuditRepository}, which has
 * no update/delete path.
 */
final readonly class SkillAuditService
{
    public function __construct(
        private SkillAuditRepository $repository,
    ) {}

    /**
     * Record a skill-scoped event (ingest, enable/disable, injection block).
     * The skill carries its denormalised trust level and injection-scan JSON.
     */
    public function recordSkillEvent(SkillAuditEvent $event, Skill $skill, string $detail = ''): void
    {
        $this->repository->record(
            $event->value,
            $skill->getSource(),
            $skill->getIdentifier(),
            $skill->getSourceSha(),
            $skill->getBodyChecksum(),
            $skill->getTrustLevel(),
            $skill->getInjectionScan(),
            $this->actorUid(),
            $detail,
        );
    }

    /**
     * Record a source-scoped event that is not tied to a single materialised
     * skill (e.g. a manifest-fingerprint rejection that blocks the whole sync).
     */
    public function recordSourceEvent(SkillAuditEvent $event, SkillSource $source, string $detail = ''): void
    {
        $this->repository->record(
            $event->value,
            (int)$source->getUid(),
            '',
            $source->getPinnedSha(),
            '',
            $source->getTrustLevel(),
            '',
            $this->actorUid(),
            $detail,
        );
    }

    /**
     * The acting backend user's uid, or 0 when no backend user is present
     * (e.g. a scheduled/CLI sync) — the row is still written, attributed to the
     * system actor.
     */
    private function actorUid(): int
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        // `->user` is untyped and is null in CLI/scheduler contexts before a
        // session is loaded — exactly the case this method documents — so
        // guard the array access to avoid an "offset on null" warning.
        if ($backendUser instanceof BackendUserAuthentication && is_array($backendUser->user)) {
            $uid = $backendUser->user['uid'] ?? 0;

            return is_numeric($uid) ? (int)$uid : 0;
        }

        return 0;
    }
}
