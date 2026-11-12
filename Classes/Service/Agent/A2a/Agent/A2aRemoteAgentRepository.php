<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Agent;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * The remote agent records (spec 051 A2A-C1).
 *
 * Like every repository of the extension it ignores enable fields and storage
 * pages (`Classes/AGENTS.md`); the runtime switch is the record's `enabled`
 * column, checked by {@see A2aRemoteAgent::useRefusal()}.
 *
 * @extends Repository<A2aRemoteAgent>
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
class A2aRemoteAgentRepository extends Repository
{
    /**
     * The pool reads the trust fields past Extbase's identity map
     * ({@see self::currentTrust()}).
     */
    public function __construct(private readonly ConnectionPool $connectionPool)
    {
        parent::__construct();
    }

    public function initializeObject(): void
    {
        $querySettings = $this->createQuery()->getQuerySettings();
        $querySettings->setRespectStoragePage(false);
        $querySettings->setIgnoreEnableFields(true);
        $this->setDefaultQuerySettings($querySettings);
    }

    /**
     * The trust fields of a record as they are in the database now (spec 051
     * A2A-R8): read past Extbase's identity map, which a long-running worker
     * keeps for its whole life, so an administrator's change to approval, data
     * class or the switch reaches a tool built before it. Null when the record
     * is gone or deleted.
     *
     * `card` is what decides where a request goes and whether the card waits
     * for confirmation: an import in another process changes these, and the
     * identity map would hide it.
     *
     * @return array{enabled: bool, requiresApproval: bool, dataClass: ToolDataClass|null, card: array{cardUrl: string, interfaceUrl: string, interfaceTenant: string, cardChanged: bool, confirmedInterface: string}}|null
     */
    public function currentTrust(int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_nrllm_a2a_agent');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('enabled', 'requires_approval', 'data_class', 'deleted', 'card_url', 'interface_url', 'interface_tenant', 'card_changed', 'confirmed_interface')
            ->from('tx_nrllm_a2a_agent')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        if (!is_array($row) || !is_numeric($row['deleted'] ?? null) || (int) $row['deleted'] !== 0) {
            return null;
        }

        return [
            'enabled' => is_numeric($row['enabled'] ?? null) && (int) $row['enabled'] === 1,
            // Anything but an explicit 0 requires approval (fail-closed).
            'requiresApproval' => !is_numeric($row['requires_approval'] ?? null) || (int) $row['requires_approval'] !== 0,
            'dataClass'        => is_string($row['data_class'] ?? null) ? ToolDataClass::tryFrom($row['data_class']) : null,
            'card'             => [
                'cardUrl'         => is_string($row['card_url'] ?? null) ? $row['card_url'] : '',
                'interfaceUrl'    => is_string($row['interface_url'] ?? null) ? $row['interface_url'] : '',
                'interfaceTenant' => is_string($row['interface_tenant'] ?? null) ? $row['interface_tenant'] : '',
                // Anything but an explicit 0 waits for confirmation (fail-closed).
                'cardChanged'        => !is_numeric($row['card_changed'] ?? null) || (int) $row['card_changed'] !== 0,
                'confirmedInterface' => is_string($row['confirmed_interface'] ?? null) ? $row['confirmed_interface'] : '',
            ],
        ];
    }

    /**
     * The record named by its identifier, or by its uid when the reference is
     * a decimal number (the command line accepts both).
     */
    public function findByReference(string $reference): ?A2aRemoteAgent
    {
        if (preg_match('/^[1-9]\d*$/', $reference) === 1) {
            $byUid = $this->findByUid((int) $reference);
            if ($byUid instanceof A2aRemoteAgent) {
                return $byUid;
            }
        }

        $query = $this->createQuery();
        $query->matching($query->equals('identifier', $reference));

        $found = $query->setLimit(1)->execute()->getFirst();

        return $found instanceof A2aRemoteAgent ? $found : null;
    }
}
