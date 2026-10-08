<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\TypoScript\PageTsConfigFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Page TSconfig of a page as a NAMED backend user sees it — the run's acting
 * user, never the ambient one (#1017).
 *
 * Core's {@see BackendUtility::getPagesTSconfig()} reads the ambient
 * `$GLOBALS['BE_USER']` three times: its workspace overlays the rootline, its
 * user TSconfig `page.` overrides are merged in, and its context aspects feed
 * the `[backend.user…]` and `[workspace…]` conditions. Its runtime cache is
 * keyed by page alone, so whichever user asked first answers for everyone in
 * the request. A tool that builds an approval preview from that answer
 * previews one user's view and resumes under another's: the approval bounces,
 * and a permission rule in `plan()` is judged against the approver's
 * TSconfig.
 *
 * This reads the same way core does — the same rootline fields, the same
 * {@see PageTsConfigFactory}, the same site lookup — with each of the three
 * inputs taken from the user passed in. The two context aspects are set for
 * the duration of the build and restored afterwards; nothing else in the
 * request sees them. No cache: the factory caches the parsed include tree, and
 * what remains is cheap next to the DataHandler work around every caller.
 *
 * A null user is "no backend user": live workspace, no user TSconfig, no
 * user in the conditions. It is never the ambient user.
 *
 * @internal
 */
final readonly class PageTsConfigReader implements PageTsConfigReaderInterface
{
    public function forPage(int $pageUid, ?BackendUserAuthentication $user): array
    {
        // -99 is core's "the ambient user's workspace" sentinel; a user whose
        // workspace was never initialised reads the live one instead.
        $workspace = $user instanceof BackendUserAuthentication ? max(0, $user->workspace) : 0;
        $rootLine  = $this->rootLine($pageUid, $workspace);

        try {
            $site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($pageUid);
        } catch (SiteNotFoundException) {
            $site = new NullSite();
        }

        $context      = GeneralUtility::makeInstance(Context::class);
        $ambientUser  = $context->getAspect('backend.user');
        $ambientSpace = $context->getAspect('workspace');
        $context->setAspect('backend.user', new UserAspect($user));
        $context->setAspect('workspace', new WorkspaceAspect($workspace));

        try {
            return GeneralUtility::makeInstance(PageTsConfigFactory::class)
                ->create($rootLine, $site, $user?->getUserTsConfig())
                ->getPageTsConfigArray();
        } finally {
            $context->setAspect('backend.user', $ambientUser);
            $context->setAspect('workspace', $ambientSpace);
        }
    }

    /**
     * The rootline from the root down, in the shape core's factory reads.
     *
     * Read without core's workspace overlay — that one uses the ambient
     * user's workspace — and overlaid here with the given workspace instead.
     *
     * @return array<array-key, array<array-key, mixed>>
     */
    private function rootLine(int $pageUid, int $workspace): array
    {
        $rootLine = [];
        foreach (BackendUtility::BEgetRootLine($pageUid, '', false) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            if ($workspace > 0) {
                $overlaid = $row;
                BackendUtility::workspaceOL('pages', $overlaid, $workspace);
                // An overlay that removes the row (a delete placeholder) keeps
                // the live row rather than leaving a hole in the rootline.
                $row = is_array($overlaid) ? $overlaid : $row;
            }

            $rootLine[$index] = $row;
        }

        ksort($rootLine);

        return $rootLine;
    }
}
