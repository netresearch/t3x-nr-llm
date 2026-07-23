<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Reconstructs the live acting backend user for a tool run from the run's
 * explicit actor (ADR-083), so the tool RBAC gate and the user-scoped tools
 * work identically whether the run executes in a backend request or in a queue
 * worker that has no ambient `$GLOBALS['BE_USER']`.
 */
interface ActingBackendUserResolverInterface
{
    /**
     * The live backend user for the actor's uid, or null when the actor is a
     * service account, anonymous, or points at a uid that no longer resolves to
     * an enabled user. Never falls back to the ambient user — a null result is
     * the fail-closed "no acting user" signal user-scoped tools honour.
     */
    public function resolve(AiActorContext $actor): ?BackendUserAuthentication;
}
