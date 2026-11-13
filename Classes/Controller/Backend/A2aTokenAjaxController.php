<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aClientTokenAdministration;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aIssuedToken;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSelfServiceTokens;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aTokenRefusedException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * The state-changing AJAX actions of the A2A token module (ADR-294): create a
 * token for one's own account, generate or rotate a technical client's token,
 * revoke one.
 *
 * Every route is a POST the core checks for its route token before this class
 * runs ({@see \Netresearch\NrLlm\Tests\Functional\Controller\Backend\A2aTokenRouteTokenTest}).
 * Each action then decides for itself who may call it, on the server, from the
 * session user: an administrator passes {@see RequiresBackendAdminTrait::denyNonAdmin()},
 * an editor needs the grant, and the services behind check the same again,
 * because the route is dispatched outside the module's own access check.
 *
 * A token is returned in the response of the action that issued it and
 * nowhere else: not in a flash message, a redirect or a log line. The response
 * is marked `no-store` so no cache keeps it. It is shown once; a later request
 * has nothing to return.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
#[AsController]
final class A2aTokenAjaxController
{
    use DefensiveLocalizationTrait;
    use RequiresBackendAdminTrait;

    private const MAX_REQUESTED_DAYS = 36500;

    public function __construct(
        private readonly A2aSelfServiceTokens $selfService,
        private readonly A2aClientTokenAdministration $administration,
    ) {}

    /**
     * An editor creates a token that acts as themselves.
     */
    public function createAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyWithoutGrant(BackendUserGrant::A2A_TOKEN_SELF_SERVICE)) instanceof ResponseInterface) {
            return $deny;
        }

        $user = $this->sessionUser();
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $now  = time();
        // Far beyond any limit, so the product below stays an int; the policy
        // refuses what the groups do not allow.
        $days = min(max($this->int($body['days'] ?? 0), 0), self::MAX_REQUESTED_DAYS);
        $uids = $body['profiles'] ?? [];

        try {
            $issued = $this->selfService->create(
                $user,
                is_string($body['title'] ?? null) ? $body['title'] : '',
                $this->int($body['workspace'] ?? 0),
                array_values(array_map($this->int(...), is_array($uids) ? $uids : [])),
                $days > 0 ? $now + $days * 86400 : 0,
                $now,
            );
        } catch (A2aTokenRefusedException $exception) {
            return $this->refused($exception);
        }

        return $this->issued($issued);
    }

    /**
     * An administrator generates or rotates the token of a technical client.
     */
    public function issueAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) instanceof ResponseInterface) {
            return $deny;
        }

        $body = $request->getParsedBody();

        try {
            $issued = $this->administration->issue($this->sessionUser(), $this->uid($body), time());
        } catch (A2aTokenRefusedException $exception) {
            return $this->refused($exception);
        }

        return $this->issued($issued);
    }

    /**
     * An administrator revokes any client record; an editor, one of their own.
     */
    public function revokeAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyWithoutGrant(BackendUserGrant::A2A_TOKEN_SELF_SERVICE)) instanceof ResponseInterface) {
            return $deny;
        }

        $user = $this->sessionUser();
        $uid  = $this->uid($request->getParsedBody());

        try {
            if ($user->isAdmin()) {
                $this->administration->revoke($user, $uid);
            } else {
                $this->selfService->revokeOwn($user, $uid);
            }
        } catch (A2aTokenRefusedException $exception) {
            return $this->refused($exception);
        }

        return new JsonResponse(['success' => true]);
    }

    private function sessionUser(): BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            throw new A2aTokenRefusedException('There is no backend user.', 1799300321);
        }

        return $user;
    }

    private function issued(A2aIssuedToken $issued): ResponseInterface
    {
        return new JsonResponse(
            ['success' => true, 'uid' => $issued->clientUid, 'token' => $issued->token, 'expires' => $issued->expires],
            200,
            ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache'],
        );
    }

    private function refused(A2aTokenRefusedException $exception): ResponseInterface
    {
        $heading = $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:a2aTokens.error.refused', 'The request was refused.');

        return new JsonResponse(['success' => false, 'error' => $heading . ' ' . $exception->getMessage()], 422, ['Cache-Control' => 'no-store, private']);
    }

    private function uid(mixed $body): int
    {
        return is_array($body) ? $this->int($body['uid'] ?? 0) : 0;
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
