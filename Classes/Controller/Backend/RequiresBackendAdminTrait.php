<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Shared admin guard for the backend module's standalone AJAX endpoints.
 *
 * The nrllm backend module is registered with ``access => admin``, but the
 * AJAX routes in ``Configuration/Backend/AjaxRoutes.php`` are dispatched
 * outside the module route and therefore bypass that access check. Any
 * authenticated backend user could otherwise reach state-mutating actions,
 * provider test-calls that decrypt vault keys, task execution, and arbitrary
 * record reads. Each AJAX action calls {@see denyNonAdmin()} at its very top
 * so only a backend admin proceeds. See ADR-037.
 */
trait RequiresBackendAdminTrait
{
    /**
     * Returns a 403 JSON response for non-admins, or null when the current backend user is an admin.
     */
    private function denyNonAdmin(): ?ResponseInterface
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($backendUser instanceof BackendUserAuthentication && $backendUser->isAdmin()) {
            return null;
        }
        return new JsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
    }
}
