<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * What a run was asked to work on (ADR-295): page subtrees, single records,
 * and optionally the tables and languages it may write.
 *
 * A record is in scope when it lies on a scoped page, or when it is a scoped
 * record or a translation of one. The tables and languages narrow that
 * further; they never add to it. A scope that names no page and no record
 * admits nothing, which is the state of a run that was asked for a scope and
 * got none ({@see self::none()}).
 *
 * It is a value, fixed when the run starts, and carried by the acting
 * identity ({@see AiActorContext}) so that a queued, suspended or resumed run
 * has the same one. No tool argument and no model output reaches it.
 *
 * @api
 */
final readonly class AssignmentScope
{
    public const MAX_DEPTH = 99;

    public const MAX_PAGES = 50;

    public const MAX_RECORDS = 200;

    public const MAX_TABLES = 50;

    public const MAX_LANGUAGES = 50;

    /** @var list<ScopedPage> */
    public array $pages;

    /** @var list<RecordReference> */
    public array $records;

    /** @var list<string>|null null when every table may be written */
    public ?array $tables;

    /** @var list<int>|null null when every language may be written */
    public ?array $languages;

    /**
     * @param list<ScopedPage>      $pages
     * @param list<RecordReference> $records
     * @param list<string>|null     $tables
     * @param list<int>|null        $languages
     */
    public function __construct(array $pages = [], array $records = [], ?array $tables = null, ?array $languages = null)
    {
        $widest = [];
        foreach ($pages as $page) {
            if (!$page instanceof ScopedPage) {
                throw new InvalidArgumentException('An assignment scope lists pages as scoped pages.', 1799990232);
            }

            $widest[$page->uid] = max($widest[$page->uid] ?? 0, $page->depth);
        }

        ksort($widest);
        $unique = [];
        foreach ($records as $record) {
            if (!$record instanceof RecordReference) {
                throw new InvalidArgumentException('An assignment scope lists records as record references.', 1799990233);
            }

            $unique[(string) $record] = $record;
        }

        ksort($unique);

        if (count($widest) > self::MAX_PAGES || count($unique) > self::MAX_RECORDS) {
            throw new InvalidArgumentException(
                sprintf('An assignment scope names at most %d pages and %d records.', self::MAX_PAGES, self::MAX_RECORDS),
                1799990234,
            );
        }

        $this->pages     = array_map(static fn (int $uid, int $depth): ScopedPage => new ScopedPage($uid, $depth), array_keys($widest), array_values($widest));
        $this->records   = array_values($unique);
        $this->tables    = $tables === null ? null : self::tables($tables);
        $this->languages = $languages === null ? null : self::languages($languages);
    }

    /**
     * A scope that admits nothing: the scope of a run that must have one and
     * was given none, or whose stored scope cannot be read.
     */
    public static function none(): self
    {
        return new self();
    }

    public static function forPage(int $uid, int $depth = 0): self
    {
        return new self([new ScopedPage($uid, $depth)]);
    }

    /**
     * The scope of one record: the page itself and its records for a page,
     * the record and its translations otherwise. The way to derive a scope
     * from a {@see SubjectRecord}.
     */
    public static function forRecord(RecordReference $record): self
    {
        return $record->table === 'pages' ? self::forPage($record->uid) : new self([], [$record]);
    }

    public function isEmpty(): bool
    {
        return $this->pages === [] && $this->records === [];
    }

    /**
     * The same scope with no page reaching deeper than $maxDepth: the
     * configuration's cap, applied to whatever the starter proposed.
     */
    public function cappedToDepth(int $maxDepth): self
    {
        $maxDepth = max(0, min(self::MAX_DEPTH, $maxDepth));

        return new self(
            array_map(static fn (ScopedPage $page): ScopedPage => new ScopedPage($page->uid, min($page->depth, $maxDepth)), $this->pages),
            $this->records,
            $this->tables,
            $this->languages,
        );
    }

    public function admitsTable(string $table): bool
    {
        return $this->tables === null || in_array($table, $this->tables, true);
    }

    public function admitsLanguage(int $language): bool
    {
        return $this->languages === null || in_array($language, $this->languages, true);
    }

    public function holdsRecord(string $table, int $uid): bool
    {
        foreach ($this->records as $record) {
            if ($record->table === $table && $record->uid === $uid) {
                return true;
            }
        }

        return false;
    }

    /**
     * A short statement of the scope for a message to the model and for the
     * trace. Names uids and tables only, never a title.
     */
    public function describe(): string
    {
        $parts = [];
        foreach ($this->pages as $page) {
            $parts[] = $page->depth === 0 ? sprintf('page %d', $page->uid) : sprintf('page %d and %d level%s below it', $page->uid, $page->depth, $page->depth === 1 ? '' : 's');
        }

        foreach ($this->records as $record) {
            $parts[] = (string) $record;
        }

        return $parts === [] ? 'nothing' : implode(', ', $parts);
    }

    /**
     * @return array{pages: list<array{uid: int, depth: int}>, records: list<array{table: string, uid: int}>, tables: list<string>|null, languages: list<int>|null}
     */
    public function toArray(): array
    {
        return [
            'pages'     => array_map(static fn (ScopedPage $page): array => $page->toArray(), $this->pages),
            'records'   => array_map(static fn (RecordReference $record): array => $record->toArray(), $this->records),
            'tables'    => $this->tables,
            'languages' => $this->languages,
        ];
    }

    /**
     * The scope a stored array describes, or null when it cannot be read. The
     * caller treats null as {@see self::none()} and never as "no scope":
     * a stored scope that is unreadable must not widen the run.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data)) {
            return null;
        }

        $pages   = $data['pages'] ?? null;
        $records = $data['records'] ?? null;
        if (!is_array($pages) || !is_array($records)) {
            return null;
        }

        try {
            $scopedPages = [];
            foreach ($pages as $page) {
                if (!is_array($page) || !is_int($page['uid'] ?? null) || !is_int($page['depth'] ?? null)) {
                    return null;
                }

                $scopedPages[] = new ScopedPage($page['uid'], $page['depth']);
            }

            $scopedRecords = [];
            foreach ($records as $record) {
                if (!is_array($record) || !is_string($record['table'] ?? null) || !is_int($record['uid'] ?? null)) {
                    return null;
                }

                $scopedRecords[] = new RecordReference($record['table'], $record['uid']);
            }

            $tables = $data['tables'] ?? null;
            if ($tables !== null && (!is_array($tables) || array_filter($tables, is_string(...)) !== $tables)) {
                return null;
            }

            $languages = $data['languages'] ?? null;
            if ($languages !== null && (!is_array($languages) || array_filter($languages, is_int(...)) !== $languages)) {
                return null;
            }

            return new self($scopedPages, $scopedRecords, $tables === null ? null : array_values($tables), $languages === null ? null : array_values($languages));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array<array-key, mixed> $tables
     *
     * @return list<string>
     */
    private static function tables(array $tables): array
    {
        $names = [];
        foreach ($tables as $table) {
            if (!is_string($table)) {
                throw new InvalidArgumentException('An assignment scope lists tables by name.', 1799990235);
            }

            // Same shape as a record reference's table, checked there.
            $names[(new RecordReference($table, 1))->table] = true;
        }

        if (count($names) > self::MAX_TABLES) {
            throw new InvalidArgumentException(sprintf('An assignment scope names at most %d tables.', self::MAX_TABLES), 1799990236);
        }

        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /**
     * @param array<array-key, mixed> $languages
     *
     * @return list<int>
     */
    private static function languages(array $languages): array
    {
        $ids = [];
        foreach ($languages as $language) {
            if (!is_int($language) || $language < 0) {
                throw new InvalidArgumentException('An assignment scope lists languages by uid of 0 or more.', 1799990237);
            }

            $ids[$language] = true;
        }

        if (count($ids) > self::MAX_LANGUAGES) {
            throw new InvalidArgumentException(sprintf('An assignment scope names at most %d languages.', self::MAX_LANGUAGES), 1799990238);
        }

        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }
}
