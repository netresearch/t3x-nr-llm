<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\McpServerRepository;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthAuthorizer;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthException;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthPendingAuthorization;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthSessionStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\RouteRedirect;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;

/**
 * Where the authorization server sends the administrator back (ADR-259).
 *
 * The route is `access: public`, which in the TYPO3 backend means "no request
 * token" — the authorization server cannot know one — not "no login": the
 * backend still requires the session, and this controller requires an
 * administrator on top. The `state` replaces the request token: it was
 * generated for this session and is looked up there.
 *
 * Whatever happens, the administrator ends up in the MCP module, which shows
 * the outcome; nothing the authorization server sent is shown.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
#[AsController]
final readonly class McpOAuthCallbackController
{
    public function __construct(
        private McpServerRepository $servers,
        private McpOAuthAuthorizer $authorizer,
        private McpOAuthSessionStore $sessions,
        private UriBuilder $uriBuilder,
        private Context $context,
    ) {}

    public function handleAction(ServerRequestInterface $request): ResponseInterface
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication || !$user->isAdmin()) {
            return new HtmlResponse('', 403);
        }

        $query   = $request->getQueryParams();
        $state   = $query['state'] ?? null;
        $pending = is_string($state) && $state !== '' ? $this->sessions->take($user, $state) : null;
        if (!$pending instanceof McpOAuthPendingAuthorization) {
            $this->sessions->rememberResult($user, 0, 'state_invalid');

            return $this->backToModule();
        }

        $server = $this->servers->findByUid($pending->serverUid);
        $owner  = McpServerController::userUid($user);
        try {
            if (!$server instanceof McpServerRecord) {
                $this->authorizer->abandon($pending);

                throw McpOAuthException::because('state_invalid');
            }

            $this->authorizer->complete($server, $pending, $query, $owner, $this->now());
            $this->sessions->rememberResult($user, $pending->serverUid, 'connected');
        } catch (McpOAuthException $exception) {
            $this->sessions->rememberResult($user, $pending->serverUid, $exception->reason());
        }

        return $this->backToModule();
    }

    private function backToModule(): ResponseInterface
    {
        // The callback is a top-level page; `main` with a redirect opens the
        // module inside the backend frame instead of on its own.
        return new RedirectResponse(
            (string) $this->uriBuilder->buildUriWithRedirect('main', [], RouteRedirect::create('nrllm_mcp', [])),
            303,
        );
    }

    private function now(): int
    {
        $now = $this->context->getPropertyFromAspect('date', 'timestamp');

        return is_int($now) ? $now : time();
    }
}
