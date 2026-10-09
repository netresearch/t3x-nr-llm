<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Page TSconfig of a page as a named backend user sees it (#1017).
 *
 * The seam exists so the tools that read it can be unit-tested without a
 * database; the one implementation is {@see PageTsConfigReader}.
 *
 * @internal
 */
interface PageTsConfigReaderInterface
{
    /**
     * The page TSconfig of `$pageUid` for `$user`: their workspace, their user
     * TSconfig `page.` overrides, and their identity in the conditions. A null
     * user is no backend user — never the ambient one.
     *
     * @return array<array-key, mixed>
     */
    public function forPage(int $pageUid, ?BackendUserAuthentication $user): array;
}
