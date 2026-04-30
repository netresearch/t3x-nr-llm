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
 * Public surface of the capability-permission gate.
 *
 * Consumers (feature services, controllers, middleware, tests) should
 * depend on this interface rather than the concrete
 * `CapabilityPermissionService` so the implementation can be substituted
 * without inheritance.
 */
interface CapabilityPermissionServiceInterface
{
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
    ): bool;

    /**
     * Build the TYPO3 permission string, e.g. "nrllm:capability_vision".
     */
    public static function permissionString(ModelCapability $capability): string;

    /**
     * The identifier used as the customPermOptions item key.
     */
    public static function permissionKey(ModelCapability $capability): string;
}
