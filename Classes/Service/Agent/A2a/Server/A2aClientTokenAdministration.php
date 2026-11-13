<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * What an administrator does with the client records in the backend (ADR-294):
 * issue or rotate the token of a technical-user record, see every record, and
 * revoke any record at once. The CLI command stays the way without a browser.
 *
 * The administrator flag is checked here as well as by the controller: this
 * class is the one place that calls
 * {@see A2aInboundClientRepository::issueToken()} for the module.
 *
 * A self-service record's token is issued by its owner only. An administrator
 * who could rotate it would hold a credential that acts as an editor.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aClientTokenAdministration
{
    public function __construct(
        private A2aInboundClientRepository $clients,
        private A2aTokenAudit $audit,
    ) {}

    /**
     * @throws A2aTokenRefusedException for a non-administrator, an unknown record or a self-service record
     */
    public function issue(BackendUserAuthentication $actor, int $clientUid, int $now): A2aIssuedToken
    {
        $this->assertAdministrator($actor);

        $client = $this->clients->findRawByUid($clientUid);
        if (!$client instanceof A2aInboundClient) {
            throw new A2aTokenRefusedException('There is no such client record.', 1799300311);
        }

        if ($client->selfService) {
            throw new A2aTokenRefusedException('The token of a self-service record is issued by its owner.', 1799300312);
        }

        $rotation = $client->tokenHash !== '';
        $token    = $this->clients->issueToken($clientUid);
        $this->audit->record($rotation ? 'rotated by an administrator' : 'issued by an administrator', $actor, $clientUid);

        return new A2aIssuedToken($clientUid, $token, $client->expires);
    }

    /**
     * @return list<A2aInboundClient>
     */
    public function listAll(BackendUserAuthentication $actor): array
    {
        $this->assertAdministrator($actor);

        return $this->clients->findForManagement();
    }

    /**
     * @throws A2aTokenRefusedException for a non-administrator or an unknown record
     */
    public function revoke(BackendUserAuthentication $actor, int $clientUid): void
    {
        $this->assertAdministrator($actor);

        if (!$this->clients->revoke($clientUid)) {
            throw new A2aTokenRefusedException('There is no such client record.', 1799300313);
        }

        $this->audit->record('revoked by an administrator', $actor, $clientUid);
    }

    private function assertAdministrator(BackendUserAuthentication $actor): void
    {
        if (!$actor->isAdmin()) {
            throw new A2aTokenRefusedException('This requires a backend administrator.', 1799300314);
        }
    }
}
