<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aDomainHandlerInterface;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aDomainHandlerRegistry;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aArtifactShaperInterface;

/**
 * The artifacts of a completed task, by its profile's domain handler (spec
 * 051 A2A-SV4). A task without a profile, or whose profile or handler is
 * gone, gets the store's one text artifact.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aProfileArtifactShaper implements A2aArtifactShaperInterface
{
    public function __construct(
        private A2aSkillProfileRepository $profiles,
        private A2aDomainHandlerRegistry $handlers,
    ) {}

    public function artifactsFor(int $profileUid, string $keptAnswer): ?array
    {
        $profile = $profileUid > 0 ? $this->profiles->findByUid($profileUid) : null;
        $handler = $profile instanceof A2aSkillProfile ? $this->handlers->get($profile->handler) : null;

        return $handler instanceof A2aDomainHandlerInterface ? $handler->artifacts($keptAnswer) : null;
    }
}
