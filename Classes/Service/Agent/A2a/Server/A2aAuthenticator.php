<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;

/**
 * Authenticates a JSON-RPC request of the server role (spec 051 A2A-SEC1,
 * ADR-280).
 *
 * - The credential is `Authorization: Bearer <token>`. The token names its
 *   record by its public prefix; the sha256 of the whole token is compared
 *   with the stored one by `hash_equals()`.
 * - A missing, malformed, unknown, wrong, disabled or expired credential, and
 *   a record whose technical backend user is no longer usable, all get the
 *   same refusal. Nothing in it says which. That includes a self-service
 *   record its owner's groups no longer cover (ADR-294): the repository
 *   returns it withdrawn, and the log line, never the response, says why.
 * - A failure is logged with the source address, never with the token, and
 *   counted per source address; a failure from an address over the limit is
 *   refused as rate limited and not logged again. Only failures are counted
 *   and limited: a valid credential passes from any address, so failed
 *   requests from one address (or from every client behind a proxy that
 *   `reverseProxyIP` does not name) cannot lock out a registered client. The
 *   token carries 256 random bits, so the limit is not what stops guessing.
 *   The limit is the rate limiter `nrllm-a2a-auth` (10 failures per 15
 *   minutes), which
 *   `$GLOBALS['TYPO3_CONF_VARS']['SYS']['rateLimiter']['nrllm-a2a-auth']`
 *   overrides like TYPO3's own limiters.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aAuthenticator
{
    public const LIMITER_ID = 'nrllm-a2a-auth';

    private const LIMITER = ['id' => self::LIMITER_ID, 'policy' => 'sliding_window', 'limit' => 10, 'interval' => '15 minutes'];

    /** Compared when no record matched, so both paths hash and compare. */
    private const NO_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    private LoggerInterface $logger;

    public function __construct(
        private A2aInboundClientRepository $clients,
        private ConnectionPool $connectionPool,
        private StorageInterface $limiterStorage,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @throws A2aRpcError UNAUTHENTICATED or RATE_LIMITED
     */
    public function authenticate(ServerRequestInterface $request, int $now): A2aInboundClient
    {
        $token  = $this->bearer($request->getHeaderLine('Authorization'));
        $prefix = A2aInboundClientRepository::prefixOf($token);
        $client = $prefix !== null ? $this->clients->findByTokenPrefix($prefix) : null;
        $valid  = hash_equals($client instanceof A2aInboundClient ? $client->tokenHash : self::NO_HASH, A2aInboundClientRepository::hash($token));

        if ($client instanceof A2aInboundClient && $valid && $client->isActive($now) && $this->usableBackendUser($client->backendUserUid, $now)) {
            return $client;
        }

        $address = $this->sourceAddress($request);
        $state   = $this->limiter($address)->consume(1);
        if (!$state->isAccepted()) {
            throw A2aRpcError::rateLimited(max(1, $state->getRetryAfter()->getTimestamp() - $now));
        }

        $this->logger->warning('A2A request refused: authentication failed', [
            'source' => $address,
            'why'    => match (true) {
                $token === ''                        => 'no bearer credential',
                !$client instanceof A2aInboundClient => 'unknown credential',
                !$valid                              => 'wrong credential',
                $client->withdrawnReason !== null    => 'self-service token withdrawn: ' . $client->withdrawnReason,
                !$client->isActive($now)             => 'disabled or expired client',
                default                              => 'unusable technical backend user',
            },
        ]);

        throw A2aRpcError::unauthenticated();
    }

    private function bearer(string $header): string
    {
        return preg_match('/^Bearer\s+(\S+)\s*$/i', $header, $match) === 1 ? $match[1] : '';
    }

    /**
     * ADR-280: an enabled backend user above 0 that is not an administrator,
     * checked at request time because the record may have changed since it
     * was saved.
     */
    private function usableBackendUser(int $uid, int $now): bool
    {
        if ($uid < 1) {
            return false;
        }

        $builder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $builder->getRestrictions()->removeAll();
        $row = $builder->select('admin', 'disable', 'deleted', 'starttime', 'endtime')
            ->from('be_users')
            ->where($builder->expr()->eq('uid', $builder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        if (!is_array($row)) {
            return false;
        }

        $int = static fn (string $key): int => is_numeric($row[$key] ?? null) ? (int) $row[$key] : 0;

        return $int('admin') === 0 && $int('disable') === 0 && $int('deleted') === 0
            && ($int('starttime') === 0 || $int('starttime') <= $now)
            && ($int('endtime') === 0 || $int('endtime') > $now);
    }

    private function sourceAddress(ServerRequestInterface $request): string
    {
        $params = $request->getAttribute('normalizedParams');
        $params = $params instanceof NormalizedParams ? $params : NormalizedParams::createFromRequest($request);

        return $params->getRemoteAddress();
    }

    private function limiter(string $address): LimiterInterface
    {
        $configuration = self::LIMITER;
        $node          = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        foreach (['SYS', 'rateLimiter', self::LIMITER_ID] as $segment) {
            $node = is_array($node) ? ($node[$segment] ?? null) : null;
        }

        if (is_array($node)) {
            $configuration = array_replace($configuration, $node);
        }

        return (new RateLimiterFactory($configuration, $this->limiterStorage))->create($address);
    }
}
