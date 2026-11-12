<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Who an A2A client acts as, and whether that actor may run a configuration
 * now. The one check for a new task, a continuation and every resume of a
 * client's run: every entry point that starts or continues a run checks the
 * configuration for its actor, and here the actor is the client's technical
 * user with the backend groups it has at this moment.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aClientAccess
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private LlmConfigurationRepository $configurations,
        private ConfigurationResolver $resolver,
        private PersistenceManagerInterface $persistence,
    ) {}

    /**
     * The client's technical user, with its current groups and the client's
     * pinned workspace.
     */
    public function actor(A2aInboundClient $client): AiActorContext
    {
        $builder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $builder->getRestrictions()->removeAll();
        $groups = $builder->select('usergroup')->from('be_users')
            ->where($builder->expr()->eq('uid', $builder->createNamedParameter($client->backendUserUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
        $groupIds = array_values(array_filter(array_map(intval(...), explode(',', is_string($groups) ? $groups : '')), static fn (int $uid): bool => $uid > 0));

        return AiActorContext::backendUser($client->backendUserUid, false, $groupIds, [], $client->workspaceId);
    }

    /**
     * Whether the configuration exists, is switched on and is one the actor
     * may use.
     */
    public function mayRun(int $configurationUid, AiActorContext $actor): bool
    {
        // Extbase keeps what it loaded for the life of the process, and a
        // worker lives for many runs: without this, a configuration switched
        // off or restricted after the worker first read it still passes. The
        // check only reads, so nothing pending is dropped.
        $this->persistence->clearState();

        $configuration = $this->configurations->findByUid($configurationUid);

        return $configuration instanceof LlmConfiguration
            && $configuration->isActive()
            && $this->resolver->actorMayUse($configuration, $actor);
    }
}
