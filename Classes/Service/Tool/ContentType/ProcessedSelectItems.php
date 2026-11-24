<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\ContentType;

use Closure;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Schema\Struct\SelectItem;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The items of a `select` whose `itemsProcFunc` computes them, for one row
 * (ADR-326): the static items with those the processor adds, as the backend
 * form computes them.
 *
 * Core's `ItemProcessingService` is not used: it turns an exception into a
 * flash message and returns the unprocessed items, and a failed processor must
 * not read as one that offers nothing.
 *
 * @internal
 */
final readonly class ProcessedSelectItems
{
    use SafeCastTrait;

    /**
     * @param list<array{value: string, name: string}> $items  the value stored and the name a model uses for it
     * @param string                                   $failed the exception class of a processor that failed, '' when none did
     */
    private function __construct(
        public array $items,
        public string $failed = '',
    ) {}

    /**
     * @param array<array-key, mixed> $config   the column's TCA config
     * @param array<string, mixed>    $row      the row as the backend form holds it: a select value is a list
     * @param array<array-key, mixed> $tsConfig the page's `TCEFORM.<table>.<column>.itemsProcFunc.`
     */
    public static function of(string $table, string $column, array $config, array $row, int $pageUid, array $tsConfig, ?BackendUserAuthentication $user): self
    {
        $function = self::toStr($config['itemsProcFunc'] ?? '');
        if ($function === '') {
            return new self([]);
        }

        $items = [];
        foreach (is_array($config['items'] ?? null) ? $config['items'] : [] as $item) {
            if (is_array($item)) {
                $items[] = SelectItem::fromTcaItemArray($item, 'select');
            }
        }

        $parameters = [
            'items'        => &$items,
            'config'       => $config,
            'TSconfig'     => $tsConfig === [] ? null : $tsConfig,
            'table'        => $table,
            'row'          => $row,
            'field'        => $column,
            'effectivePid' => $pageUid,
            'site'         => self::siteOf($pageUid),
        ];

        // A processor asks the ambient user, and the acting one decides here.
        $previousUser = $GLOBALS['BE_USER'] ?? null;
        if ($user instanceof BackendUserAuthentication) {
            $GLOBALS['BE_USER'] = $user;
        }

        try {
            $caller = new self([]);
            GeneralUtility::callUserFunction($function, $parameters, $caller);

            return new self(self::named(is_array($items) ? $items : []));
        } catch (Throwable $failure) {
            return new self([], $failure::class);
        } finally {
            $GLOBALS['BE_USER'] = $previousUser;
            if ($previousUser === null) {
                unset($GLOBALS['BE_USER']);
            }
        }
    }

    /**
     * Only the items whose value passes.
     *
     * @param Closure(string): bool $passes
     */
    public function only(Closure $passes): self
    {
        return new self(
            array_values(array_filter($this->items, static fn (array $item): bool => $passes($item['value']))),
            $this->failed,
        );
    }

    /**
     * The stored value for what a model gave — an item's value, or the name of
     * exactly one item — or null when no item is meant.
     */
    public function valueOf(string $given): ?string
    {
        foreach ($this->items as $item) {
            if ($item['value'] === $given) {
                return $given;
            }
        }

        $matches = array_values(array_filter(
            $this->items,
            static fn (array $item): bool => mb_strtolower($item['name']) === mb_strtolower($given),
        ));

        return count($matches) === 1 ? $matches[0]['value'] : null;
    }

    /**
     * The names of the items that hold a value.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_column(array_filter($this->items, static fn (array $item): bool => $item['value'] !== ''), 'name');
    }

    /**
     * The names closest to what a model gave: those that contain it or are
     * contained in it first, then by spelling.
     *
     * @return list<string>
     */
    public function namesNear(string $given, int $limit): array
    {
        $names = $this->names();
        if (count($names) <= $limit) {
            return $names;
        }

        $needle   = mb_strtolower(mb_substr($given, 0, 60));
        $distance = static function (string $name) use ($needle): int {
            $name = mb_strtolower($name);
            if ($needle !== '' && (str_contains($name, $needle) || str_contains($needle, $name))) {
                return 0;
            }

            return 1 + levenshtein($needle, mb_substr($name, 0, 60));
        };

        usort($names, static fn (string $a, string $b): int => [$distance($a), $a] <=> [$distance($b), $b]);

        return array_slice($names, 0, $limit);
    }

    private static function siteOf(int $pageUid): SiteInterface
    {
        try {
            return $pageUid > 0 ? GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($pageUid) : new NullSite();
        } catch (SiteNotFoundException) {
            return new NullSite();
        }
    }

    /**
     * The items a value can be chosen from. An item's name is its label where
     * that is plain text and no other item carries it as a label or a value;
     * a file path as a value is long, and a list of hundreds of them costs a
     * model's context. Everything else is named by its value.
     *
     * @param array<array-key, mixed> $items
     *
     * @return list<array{value: string, name: string}>
     */
    private static function named(array $items): array
    {
        $choices = [];
        foreach ($items as $item) {
            $item = is_array($item) ? SelectItem::fromTcaItemArray($item, 'select') : $item;
            if (!$item instanceof SelectItem || $item->isDivider()) {
                continue;
            }

            // A list, not a map by value: a numeric value would become an integer key.
            $choices[] = ['value' => self::toStr($item->getValue()), 'name' => trim($item->getLabel())];
        }

        $values   = array_column($choices, 'value');
        $carriers = array_count_values(array_map(mb_strtolower(...), array_column($choices, 'name')));

        return array_map(
            static function (array $choice) use ($values, $carriers): array {
                $label  = $choice['name'];
                $usable = $label !== '' && !str_starts_with($label, 'LLL:')
                    && $carriers[mb_strtolower($label)] === 1
                    && ($label === $choice['value'] || !in_array($label, $values, true));

                return ['value' => $choice['value'], 'name' => $usable ? $label : $choice['value']];
            },
            $choices,
        );
    }
}
