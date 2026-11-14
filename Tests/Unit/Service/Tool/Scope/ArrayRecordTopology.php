<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Scope;

use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Service\Tool\Scope\RecordFacts;
use Netresearch\NrLlm\Service\Tool\Scope\RecordTopologyInterface;

/**
 * A page tree and some records held in an array, for the scope checker.
 *
 *     0
 *     └ 1 site root
 *       ├ 2 scoped page
 *       │ ├ 3 child (translation row 13, language 1)
 *       │ │ └ 4 grandchild
 *       │ └ 6 second child
 *       └ 5 other page
 *         └ 7 other child
 */
class ArrayRecordTopology implements RecordTopologyInterface
{
    /** @var array<string, RecordFacts> */
    private array $rows = [];

    /** @var array<int, list<int>> */
    private array $children = [1 => [2, 5], 2 => [3, 6], 3 => [4], 5 => [7]];

    public function __construct()
    {
        foreach ([1 => 0, 2 => 1, 3 => 2, 4 => 3, 5 => 1, 6 => 2, 7 => 5] as $uid => $pid) {
            $this->rows['pages:' . $uid] = new RecordFacts($uid, $pid);
        }

        // The German overlay of page 3 sits beside it, under the same parent.
        $this->rows['pages:13'] = new RecordFacts(13, 2, 1, 3);

        foreach ([21 => 2, 22 => 3, 23 => 5, 24 => 4, 25 => 0] as $uid => $pid) {
            $this->rows['tt_content:' . $uid] = new RecordFacts($uid, $pid);
        }

        // A German translation of the content element 21, and one of the foreign 23.
        $this->rows['tt_content:31']        = new RecordFacts(31, 2, 1, 21);
        $this->rows['tt_content:33']        = new RecordFacts(33, 5, 1, 23);
        $this->rows['sys_file:90']          = new RecordFacts(90, 0);
        $this->rows['sys_file_metadata:91'] = new RecordFacts(91, 0, 0, null, new RecordReference('sys_file', 90));
        $this->rows['sys_file_metadata:92'] = new RecordFacts(92, 0, 0, null, new RecordReference('sys_file', 99));
        $this->rows['sys_file:99']          = new RecordFacts(99, 0);
    }

    public function row(string $table, int $uid, int $workspace): ?RecordFacts
    {
        return $this->rows[$table . ':' . $uid] ?? null;
    }

    public function languagesOnPage(int $pageUid, int $workspace): array
    {
        $languages = [];
        foreach ($this->rows as $key => $facts) {
            if (str_starts_with($key, 'tt_content:') && $facts->pid === $pageUid || $key === 'pages:' . $facts->liveUid && $facts->translationOf === $pageUid) {
                $languages[] = $facts->language;
            }
        }

        return array_values(array_unique($languages));
    }

    public function storedValue(string $table, int $uid, string $field): ?string
    {
        return null;
    }

    public function translationLanguages(string $table, int $uid, int $workspace): array
    {
        $languages = [];
        foreach ($this->rows as $key => $facts) {
            if (str_starts_with($key, $table . ':') && $facts->translationOf === $uid) {
                $languages[] = $facts->language;
            }
        }

        return array_values(array_unique($languages));
    }

    public function descendantPages(int $pageUid, int $workspace, int $limit): ?array
    {
        $found = [];
        $queue = [$pageUid];
        while ($queue !== []) {
            $next = array_shift($queue);
            foreach ($this->children[$next] ?? [] as $child) {
                $found[] = $child;
                $queue[] = $child;
            }
        }

        return count($found) > $limit ? null : $found;
    }
}
