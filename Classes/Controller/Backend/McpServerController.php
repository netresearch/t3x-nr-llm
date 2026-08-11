<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\ValueObject\McpConnectionReport;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\McpClient;
use Netresearch\NrLlm\Service\Tool\Mcp\McpImportService;
use Netresearch\NrLlm\Service\Tool\Mcp\McpServerRepository;
use Netresearch\NrLlm\Service\Tool\Mcp\McpToolRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * The MCP server module: what is configured, what was imported, import now (ADR-116).
 *
 * The import is an explicit administrator action and lives only here. It talks
 * to a third party over the network, so it must never happen because a page
 * rendered or because a run needed a tool — {@see McpImportService} is called
 * from this one place and from nothing else.
 *
 * The connection test beside it (ADR-154) is the same outbound call without the
 * write: it performs the handshake and reports, so an operator can tell a
 * server that is down from one whose catalogue is stale without rewriting the
 * catalogue to find out.
 *
 * Every action is admin-gated. The module registration already restricts the
 * list; each AJAX route needs its own check because a backend route bypasses
 * the module's `access` setting (ADR-037), and both of them are exactly the
 * kind of outbound action that must not be reachable without it.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
#[AsController]
final class McpServerController extends ActionController
{
    use DefensiveLocalizationTrait;
    use RequiresBackendAdminTrait;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly McpServerRepository $servers,
        private readonly McpToolRepository $catalogue,
        private readonly McpImportService $importer,
        private readonly McpClient $client,
        private readonly PageRenderer $pageRenderer,
    ) {}

    public function listAction(): ResponseInterface
    {
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/McpImport.js');
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();

        $servers = [];
        foreach ($this->servers->findAll() as $server) {
            $servers[] = [
                'record' => $server,
                // An undeclared data class is the one configuration mistake that
                // silently costs a server all of its tools, so the view is told
                // about it rather than leaving the operator to infer it from an
                // empty list.
                'usable' => $server->dataClassEnum() !== null,
                // Resolved here rather than compared in the template: the
                // fail-closed reading of the stored flag has exactly one home
                // (ADR-134), and a Fluid condition would be a second one.
                'approvalRequired' => $server->approvalRequired(),
                'tools'            => $this->catalogue->findAllByServer($server->uid),
                // Stated, not stored. HTTP is the only transport this client
                // speaks (ADR-116), and a column would imply a choice the
                // operator does not have — but the readout has to say it,
                // because "which transport" is the first question asked of a
                // server that will not answer.
                'transport' => 'HTTP (JSON-RPC)',
                // Composed here rather than in the template because the
                // connection test rewrites this exact line in the rendered
                // page (ADR-154) and both must say the same thing.
                'contact' => $this->contactLabel($server),
            ];
        }

        $moduleTemplate->assign('servers', $servers);

        return $moduleTemplate->renderResponse('Backend/McpServer/List');
    }

    /**
     * Import one server's advertised catalogue (AJAX, admin-gated).
     *
     * Returns the report rather than a bare success flag: a refusal, an import
     * that wrote nothing and an import that skipped half the catalogue are three
     * different outcomes, and an operator who sees only "done" cannot tell them
     * apart.
     */
    public function importAction(ServerRequestInterface $request): ResponseInterface
    {
        $server = $this->namedServer($request);
        if (!$server instanceof McpServerRecord) {
            return $server;
        }

        $report = $this->importer->import($server);

        // A refusal answers 200 with `success: false`, like every other action
        // in this backend: the shared front-end helper reads that shape and
        // shows the reason, while a non-2xx would land in its generic transport
        // error path and lose the message the operator needs. 404 above is the
        // exception because there the request itself was wrong.
        return new JsonResponse([
            'success'     => !$report->refused,
            'error'       => $report->refused ? ($report->skipReasons[0] ?? '') : '',
            'imported'    => $report->imported,
            'skipped'     => $report->skipped,
            'orphaned'    => $report->orphaned,
            'skipReasons' => $report->skipReasons,
        ]);
    }

    /**
     * Check whether one server answers, without touching its catalogue (AJAX, admin-gated).
     *
     * The counterpart to the import above: an operator who wants to know
     * whether a server is alive should not have to run the one action that
     * rewrites what its tools are. A failed handshake is reported here and
     * stored nowhere — see {@see McpClient::ping()}.
     *
     * Both strings are composed here, and that is deliberate. Nothing stores
     * the protocol revision or the server's self-description (ADR-154), so
     * they exist only in this response; the module renders them into the
     * server's card, and the page is not reloaded, because the reload would
     * destroy the answer. `contact` is the refreshed liveness line — the one
     * thing a reload WOULD have brought — read back from the row the ping
     * just stamped, in the same wording the initial render uses.
     */
    public function testConnectionAction(ServerRequestInterface $request): ResponseInterface
    {
        $server = $this->namedServer($request);
        if (!$server instanceof McpServerRecord) {
            return $server;
        }

        $report = $this->client->ping($server);

        // Same shape as the import: an unreachable server answers 200 with
        // `success: false`, because the request was fine and the answer is the
        // finding. The shared front-end helper shows `error` for it.
        if (!$report->reachable) {
            return new JsonResponse([
                'success' => false,
                'error'   => $report->error,
            ]);
        }

        $stamped = $this->servers->findByUid($server->uid);

        return new JsonResponse([
            'success' => true,
            'error'   => '',
            'report'  => $this->connectionReport($report),
            'contact' => $this->contactLabel($stamped instanceof McpServerRecord ? $stamped : $server),
        ]);
    }

    /**
     * What one handshake learned, as one line for the operator.
     *
     * The latency is also stored; the protocol revision and the
     * self-description are not, and this is the only place they are ever
     * shown (ADR-154). The two remote strings arrive clipped and
     * control-stripped from {@see McpClient} and are rendered as text.
     */
    private function connectionReport(McpConnectionReport $report): string
    {
        $parts = [sprintf('%d ms', $report->latencyMs)];

        if ($report->protocolVersion !== '') {
            $parts[] = $this->localize(
                'LLL:EXT:nr_llm/Resources/Private/Language/locallang_mod_mcp.xlf:server.report.protocol',
                'Protocol',
            ) . ' ' . $report->protocolVersion;
        }

        $identity = trim($report->serverName . ' ' . $report->serverVersion);
        if ($identity !== '') {
            $parts[] = $identity;
        }

        return $this->localize(
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang_mod_mcp.xlf:server.report.reachable',
            'Reachable',
        ) . ' — ' . implode(' · ', $parts);
    }

    /**
     * The card's liveness line: when the server last answered and how long it
     * took, or that it never has.
     */
    private function contactLabel(McpServerRecord $server): string
    {
        if ($server->lastContact <= 0) {
            return $this->localize(
                'LLL:EXT:nr_llm/Resources/Private/Language/locallang_mod_mcp.xlf:server.lastContact.never',
                'Never reached',
            );
        }

        return sprintf(
            '%s — %s: %d ms',
            date('Y-m-d H:i', $server->lastContact),
            $this->localize(
                'LLL:EXT:nr_llm/Resources/Private/Language/locallang_mod_mcp.xlf:server.latency',
                'Latency',
            ),
            $server->lastLatencyMs,
        );
    }

    /**
     * The server the request named, or the response to send instead.
     *
     * Both actions reach an external party on behalf of an administrator, so
     * both answer the same two questions first: is the caller an admin (the
     * AJAX route bypasses the module's `access` setting, ADR-037), and did the
     * caller name a server that exists.
     */
    private function namedServer(ServerRequestInterface $request): McpServerRecord|ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) instanceof ResponseInterface) {
            return $deny;
        }

        $uid    = $this->intFromBody($request->getParsedBody(), 'server');
        $server = $uid > 0 ? $this->servers->findByUid($uid) : null;

        if (!$server instanceof McpServerRecord) {
            return new JsonResponse([
                'success' => false,
                'error'   => $this->localize(
                    'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.mcp.unknownServer',
                    'Unknown MCP server',
                ),
            ], 404);
        }

        return $server;
    }

    /**
     * A uid, or 0 for anything that is not one.
     *
     * Validated rather than cast. `is_numeric()` accepts "1.9" and "1e3", and
     * casting either yields a uid the caller never named — the import would
     * then reach an external server chosen by a rounding rule. A form-encoded
     * body always carries strings, so the string case is the normal one; a JSON
     * body can carry a real integer.
     */
    private function intFromBody(mixed $body, string $key): int
    {
        if (!is_array($body)) {
            return 0;
        }

        $value = $body[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return 0;
        }

        $uid = filter_var($value, FILTER_VALIDATE_INT);

        return $uid === false ? 0 : $uid;
    }
}
