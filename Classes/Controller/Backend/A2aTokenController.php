<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aInboundClient;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aInboundClientRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSelfServiceLimits;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSelfServicePolicy;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSkillProfile;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSkillProfileRepository;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * The A2A tokens of the backend (ADR-294): an administrator's list of every
 * client record with "Generate token", "Rotate token" and "Revoke"; an
 * editor's list of their own tokens with the form that creates one.
 *
 * Read-only: every change is an AJAX POST to {@see A2aTokenAjaxController},
 * whose route token the core checks. What each user sees is decided here, on
 * the server, from the session user: an editor's list is
 * {@see A2aInboundClientRepository::findForManagement()} with the owner in the
 * query, never a filter applied to the full list, and a user without the grant
 * who is not an administrator gets a 403 and no data.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
#[AsController]
final class A2aTokenController extends ActionController
{
    use ModuleChromeTrait;
    use RequiresBackendAdminTrait;

    public const ROUTE = 'nrllm_a2atokens';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly A2aInboundClientRepository $clients,
        private readonly A2aSelfServicePolicy $policy,
        private readonly A2aSkillProfileRepository $profiles,
        private readonly ConnectionPool $connectionPool,
        private readonly PageRenderer $pageRenderer,
    ) {}

    public function indexAction(): ResponseInterface
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            return new HtmlResponse('', 403);
        }

        // The module switch alone never grants: an editor needs the grant, an
        // administrator passes.
        if (($deny = $this->denyWithoutGrantHtml(BackendUserGrant::A2A_TOKEN_SELF_SERVICE)) instanceof ResponseInterface) {
            return $deny;
        }

        $isAdmin = $user->isAdmin();
        $limits  = $this->policy->limitsOfSessionUser($user);
        $ownUid  = is_array($user->user) && is_numeric($user->user['uid'] ?? null) ? (int) $user->user['uid'] : 0;
        $records = $this->clients->findForManagement($isAdmin ? null : $ownUid);

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->applyModuleChrome($moduleTemplate, $this->request);
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/A2aTokens.js');

        $moduleTemplate->assignMultiple([
            'isAdmin'     => $isAdmin,
            'limits'      => $limits,
            'rows'        => $this->rows($records, $isAdmin),
            'workspaces'  => $isAdmin ? [] : $this->workspaces($user, $limits),
            'profiles'    => $isAdmin ? [] : $this->profileChoices($limits),
            'maxDays'     => $limits->maxExpiryDays,
            'defaultDays' => min(30, $limits->maxExpiryDays),
            'ceilingDays' => A2aSelfServiceLimits::CEILING_DAYS,
            'canCreate'   => !$isAdmin && $limits->permitted,
        ]);

        return $moduleTemplate->renderResponse('Backend/A2aToken/Index');
    }

    /**
     * @param list<A2aInboundClient> $records
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $records, bool $isAdmin): array
    {
        $now      = time();
        $lastTask = $this->clients->lastTaskTimes(array_map(static fn (A2aInboundClient $c): int => $c->uid, $records));
        $owners   = $isAdmin ? $this->usernames(array_map(static fn (A2aInboundClient $c): int => $c->backendUserUid, $records)) : [];

        $rows = [];
        foreach ($records as $record) {
            $effective = $this->policy->constrain($record, $now);
            $status    = $this->status($effective, $now);
            $rows[]    = [
                'uid'         => $record->uid,
                'title'       => $record->title,
                'selfService' => $record->selfService,
                'owner'       => $owners[$record->backendUserUid] ?? '',
                'created'     => $record->createdAt,
                'expires'     => $record->expires,
                'lastTask'    => $lastTask[$record->uid] ?? 0,
                'status'      => $status,
                'badge'       => match ($status) {
                    'active'    => 'success',
                    'withdrawn' => 'danger',
                    default     => 'default',
                },
                'reason'    => $effective->withdrawnReason ?? '',
                'canIssue'  => $isAdmin && !$record->selfService,
                'canRevoke' => $record->enabled || $record->tokenHash !== '',
                'hasToken'  => $record->tokenHash !== '',
            ];
        }

        return $rows;
    }

    private function status(A2aInboundClient $client, int $now): string
    {
        return match (true) {
            $client->withdrawnReason !== null                => 'withdrawn',
            $client->tokenHash === ''                        => 'noToken',
            !$client->enabled                                => 'disabled',
            $client->expires > 0 && $client->expires <= $now => 'expired',
            default                                          => 'active',
        };
    }

    /**
     * @param list<int> $uids
     *
     * @return array<int, string>
     */
    private function usernames(array $uids): array
    {
        $uids = array_values(array_unique(array_filter($uids, static fn (int $uid): bool => $uid > 0)));
        if ($uids === []) {
            return [];
        }

        $builder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $builder->getRestrictions()->removeAll();
        $rows = $builder->select('uid', 'username')->from('be_users')
            ->where($builder->expr()->in('uid', $builder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
            ->executeQuery()
            ->fetchAllAssociative();

        $names = [];
        foreach ($rows as $row) {
            if (is_numeric($row['uid'] ?? null) && is_string($row['username'] ?? null)) {
                $names[(int) $row['uid']] = $row['username'];
            }
        }

        return $names;
    }

    /**
     * The workspaces the editor may pin a token to: the ones they can work in,
     * and live only when a group allows it. What is offered is a convenience;
     * the writer checks the choice again.
     *
     * @return list<array{uid: int, title: string}>
     */
    private function workspaces(BackendUserAuthentication $user, A2aSelfServiceLimits $limits): array
    {
        $choices = [];
        if ($limits->allowLive && $user->checkWorkspace(0) !== false) {
            $choices[] = ['uid' => 0, 'title' => 'Live'];
        }

        // Without the workspaces extension there is only live, and no table.
        if (!ExtensionManagementUtility::isLoaded('workspaces')) {
            return $choices;
        }

        $builder = $this->connectionPool->getQueryBuilderForTable('sys_workspace');
        $rows    = $builder->select('uid', 'title')->from('sys_workspace')->orderBy('title')->executeQuery()->fetchAllAssociative();
        foreach ($rows as $row) {
            $uid = is_numeric($row['uid'] ?? null) ? (int) $row['uid'] : 0;
            if ($uid > 0 && $user->checkWorkspace($uid) !== false) {
                $choices[] = ['uid' => $uid, 'title' => is_string($row['title'] ?? null) ? $row['title'] : (string) $uid];
            }
        }

        return $choices;
    }

    /**
     * @return list<A2aSkillProfile>
     */
    private function profileChoices(A2aSelfServiceLimits $limits): array
    {
        return $this->profiles->findPublicByUids($limits->profileUids);
    }
}
