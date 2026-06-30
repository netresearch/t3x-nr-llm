<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Resolves the acting backend user for per-tool RBAC self-enforcement.
 *
 * The acting user is the real logged-in {@see BackendUserAuthentication} in
 * `$GLOBALS['BE_USER']`. Both accessors fail CLOSED: when no backend user is
 * present (or it is not a {@see BackendUserAuthentication}) the user is null
 * and {@see actingUserIsAdmin()} is false, so a tool treats the situation as
 * "no access / not an admin" rather than silently running unscoped.
 */
trait ResolvesActingBackendUserTrait
{
    /**
     * The real logged-in backend user, or null when none is present
     * (fail-closed: callers must treat null as "no access").
     */
    private function actingBackendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }

    /**
     * True only when a real backend user is present AND is an admin. Absent a
     * backend user this is false (fail-closed), so an admin-only code path
     * never runs without a verified admin.
     */
    private function actingUserIsAdmin(): bool
    {
        $backendUser = $this->actingBackendUser();

        return $backendUser !== null && $backendUser->isAdmin();
    }
}
