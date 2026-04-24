<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Checks whether the current backend user is allowed to invoke a given
 * model capability, using TYPO3's native `customPermOptions` mechanism.
 *
 * Registration lives in ext_localconf.php under
 * `$TYPO3_CONF_VARS['BE']['customPermOptions']['nrllm']`. Editors see a
 * checkbox per capability on the BE group edit view; admins bypass all
 * checks. Non-backend contexts (CLI, scheduler, frontend) are always
 * allowed — capability gating is a backend-editor concern.
 *
 * This service is intentionally thin so callers can decide WHERE to
 * enforce: a feature service's entry method, a controller, or middleware.
 * Call isAllowed() before dispatching; branch on the result or throw an
 * AccessDeniedException with context.
 */
final readonly class CapabilityPermissionService
{
    public const PERM_NAMESPACE = 'nrllm';

    /**
     * Check if the given capability is allowed for the (optional) backend user.
     *
     * Resolution rules, in order:
     *   1. No BE user (CLI / frontend) -> allowed
     *   2. User is admin -> allowed
     *   3. Delegate to $user->check('custom_options', 'nrllm:capability_X')
     */
    public function isAllowed(
        ModelCapability $capability,
        ?BackendUserAuthentication $backendUser = null,
    ): bool {
        $user = $backendUser ?? $this->resolveGlobalUser();

        if (!$user instanceof BackendUserAuthentication) {
            return true;
        }

        if ($this->isAdmin($user)) {
            return true;
        }

        return $user->check('custom_options', self::permissionString($capability));
    }

    /**
     * Build the TYPO3 permission string, e.g. "nrllm:capability_vision".
     */
    public static function permissionString(ModelCapability $capability): string
    {
        return self::PERM_NAMESPACE . ':' . self::permissionKey($capability);
    }

    /**
     * The identifier used as the customPermOptions item key.
     */
    public static function permissionKey(ModelCapability $capability): string
    {
        return 'capability_' . $capability->value;
    }

    private function resolveGlobalUser(): ?BackendUserAuthentication
    {
        // Direct $GLOBALS access is the only way to read the active BE user
        // outside backend controllers. Refactoring this to Context-API
        // injection would require touching every caller (capability checks
        // run from CLI, scheduler, FE, and BE contexts).
        $candidate = $GLOBALS['BE_USER'] ?? null;
        return $candidate instanceof BackendUserAuthentication ? $candidate : null;
    }

    private function isAdmin(BackendUserAuthentication $user): bool
    {
        return $user->isAdmin();
    }
}
