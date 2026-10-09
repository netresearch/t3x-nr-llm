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
 * This reads the same way core does — the same rootline walk and fields, the
 * same {@see PageTsConfigFactory}, the same site lookup — with each of the
 * three inputs taken from the user passed in. The two context aspects are set for
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
            // Given the rootline built above. Without it the finder resolves its
            // own through the request's Context, i.e. in the ambient workspace,
            // and misses a page that exists only in the acting user's.
            $site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($pageUid, array_reverse($rootLine));
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
     * The fields core's rootline carries ({@see BackendUtility::BEgetRootLine()}),
     * the same in TYPO3 13.4 and 14.3.
     */
    private const ROOTLINE_FIELDS = [
        'uid', 'pid', 'title', 'doktype', 'slug', 'tsconfig_includes', 'TSconfig', 'is_siteroot',
        't3ver_oid', 't3ver_wsid', 't3ver_state', 't3ver_stage', 'backend_layout', 'backend_layout_next_level',
        'hidden', 'starttime', 'endtime', 'fe_group', 'nav_hide', 'content_from_pid', 'module', 'extendToSubpages',
    ];

    /**
     * The rootline from the root down, in the shape core's factory reads.
     *
     * Walked the way {@see BackendUtility::BEgetRootLine()} walks it with the
     * overlay switched on — each page overlaid BEFORE its `pid` picks the next
     * one, so a page moved in the workspace climbs its new parents — but with
     * the given workspace, where core takes the ambient user's. Core's runtime
     * cache keys that walk by page and the overlay flag only, not by
     * workspace, so it cannot be asked instead. Like core, the synthetic root
     * entry (uid 0) is not overlaid.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rootLine(int $pageUid, int $workspace): array
    {
        $walked = [];
        $uid    = $pageUid;
        $guard  = 100;
        while ($uid !== 0 && $guard-- > 0) {
            // A comma list: TYPO3 13.4 types $fields as string, 14.3 takes an
            // array too.
            $row = BackendUtility::getRecord('pages', $uid, implode(',', self::ROOTLINE_FIELDS));
            if ($row === null) {
                break;
            }

            if ($workspace > 0) {
                BackendUtility::workspaceOL('pages', $row, $workspace);
                if (!is_array($row)) {
                    break;
                }
            }

            $pid      = $row['pid'] ?? 0;
            $uid      = is_numeric($pid) ? (int)$pid : 0;
            $walked[] = $row;
        }

        $root = array_fill_keys(self::ROOTLINE_FIELDS, null);
        if ($uid === 0) {
            $root['uid'] = 0;
            $walked[]    = $root;
        }

        // Numbered as core numbers it: the root entry 0, the page highest.
        $rootLine = [];
        $index    = count($walked);
        foreach ($walked as $row) {
            --$index;
            $entry = array_intersect_key($row, $root);
            if (isset($row['_ORIG_pid'])) {
                $entry['_ORIG_pid'] = $row['_ORIG_pid'];
            }

            $rootLine[$index] = $entry;
        }

        ksort($rootLine);

        return $rootLine;
    }
}
