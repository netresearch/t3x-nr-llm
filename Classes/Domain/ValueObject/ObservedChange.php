<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\ChangeAction;
use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * One record a DataHandler run wrote during a tool call (ADR-292).
 *
 * An identity plus a few facts about the stored row, and no value the record
 * holds: no title, no field content (ADR-064, ADR-182). The facts are separate
 * fields on purpose, because they answer different questions and none implies
 * another:
 *
 * - `workspace`: where the row is stored. 0 is live; above 0 it is a draft
 *   version, and `liveUid` names the live record it is the version of.
 * - `hidden`: the row's own disable flag, or null where its table has none.
 * - `restrictions`: the time and group restrictions the row itself carries.
 *
 * A row with `hidden` false is not thereby public: it can sit in a draft
 * workspace, carry a restriction, or lie below a hidden page. Frontend
 * visibility is not checked, and no field states it.
 *
 * `isWriteTarget` is true for the record the tool named in its result
 * (`writeTarget`); every other change is additional: a record core wrote
 * along (a copy's children, a translation's overlays) or one a hook of the
 * installation wrote. The two cannot be told apart and are not claimed to be.
 *
 * @api
 */
final readonly class ObservedChange
{
    /** The restrictions {@see self::$restrictions} may name. */
    public const RESTRICTIONS = ['starttime', 'endtime', 'fe_group'];

    /** @var list<string> */
    public array $restrictions;

    /**
     * @param list<string> $restrictions a subset of {@see self::RESTRICTIONS}
     */
    public function __construct(
        public RecordReference $record,
        public ChangeAction $action,
        public int $workspace = 0,
        public ?int $liveUid = null,
        public ?int $language = null,
        public bool $isWriteTarget = false,
        public ?bool $hidden = null,
        array $restrictions = [],
    ) {
        $known = array_values(array_intersect(self::RESTRICTIONS, $restrictions));

        $this->restrictions = $known;
    }

    /**
     * Whether the row is a version in a draft workspace.
     */
    public function isDraft(): bool
    {
        return $this->workspace > 0;
    }

    /**
     * @return array{table: string, uid: int, action: string, workspace: int, draft: bool, liveUid?: int, language?: int, writeTarget: bool, hidden?: bool, restrictions: list<string>}
     */
    public function toArray(): array
    {
        $out = [
            'table'     => $this->record->table,
            'uid'       => $this->record->uid,
            'action'    => $this->action->value,
            'workspace' => $this->workspace,
            'draft'     => $this->isDraft(),
        ];
        if ($this->liveUid !== null) {
            $out['liveUid'] = $this->liveUid;
        }

        if ($this->language !== null) {
            $out['language'] = $this->language;
        }

        $out['writeTarget'] = $this->isWriteTarget;
        if ($this->hidden !== null) {
            $out['hidden'] = $this->hidden;
        }

        $out['restrictions'] = $this->restrictions;

        return $out;
    }

    /**
     * The change a stored payload describes, or null for one that is not a
     * change (a foreign or corrupt entry): a reader skips it and keeps the rest.
     *
     * @param array<mixed> $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $table  = $data['table'] ?? null;
        $uid    = $data['uid'] ?? null;
        $action = is_string($data['action'] ?? null) ? ChangeAction::tryFrom($data['action']) : null;
        if (!is_string($table) || !is_int($uid) || !$action instanceof ChangeAction) {
            return null;
        }

        $restrictions = [];
        foreach (is_array($data['restrictions'] ?? null) ? $data['restrictions'] : [] as $restriction) {
            if (is_string($restriction)) {
                $restrictions[] = $restriction;
            }
        }

        try {
            return new self(
                new RecordReference($table, $uid),
                $action,
                is_int($data['workspace'] ?? null) ? $data['workspace'] : 0,
                is_int($data['liveUid'] ?? null) ? $data['liveUid'] : null,
                is_int($data['language'] ?? null) ? $data['language'] : null,
                ($data['writeTarget'] ?? false) === true,
                is_bool($data['hidden'] ?? null) ? $data['hidden'] : null,
                $restrictions,
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
