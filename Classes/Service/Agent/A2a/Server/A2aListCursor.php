<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use TYPO3\CMS\Core\Crypto\HashService;

/**
 * The opaque `ListTasks` page token (spec 051 A2A-SV6 [B11]): the position
 * after the last task of a page, signed together with what it was issued
 * for, the client, the client's profile set and the filter. A token used by
 * another client, after the client's profiles changed, or with another
 * filter does not validate.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aListCursor
{
    private const SECRET = 'nr_llm-a2a-list-cursor';

    public function __construct(
        private HashService $hashService,
    ) {}

    /**
     * @param list<int> $profileUids
     */
    public function issue(int $clientUid, array $profileUids, A2aListFilter $filter, int $timestamp, string $taskId): string
    {
        $position = rtrim(strtr(base64_encode(json_encode(['t' => $timestamp, 'i' => $taskId], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $position . '.' . $this->hashService->hmac($this->bound($position, $clientUid, $profileUids, $filter), self::SECRET);
    }

    /**
     * The position a token names, null when it is not one this server issued
     * for exactly this client, profile set and filter.
     *
     * @param list<int> $profileUids
     *
     * @return array{ts: int, id: string}|null
     */
    public function read(string $token, int $clientUid, array $profileUids, A2aListFilter $filter): ?array
    {
        $dot = strrpos($token, '.');
        if ($dot === false) {
            return null;
        }

        $position = substr($token, 0, $dot);
        if (!$this->hashService->validateHmac($this->bound($position, $clientUid, $profileUids, $filter), self::SECRET, substr($token, $dot + 1))) {
            return null;
        }

        $decoded = json_decode((string) base64_decode(strtr($position, '-_', '+/'), true), true);
        if (!is_array($decoded) || !is_int($decoded['t'] ?? null) || !is_string($decoded['i'] ?? null)) {
            return null;
        }

        return ['ts' => $decoded['t'], 'id' => $decoded['i']];
    }

    /**
     * @param list<int> $profileUids
     */
    private function bound(string $position, int $clientUid, array $profileUids, A2aListFilter $filter): string
    {
        sort($profileUids);

        return implode('|', [$position, (string) $clientUid, implode(',', $profileUids), $filter->fingerprint()]);
    }
}
