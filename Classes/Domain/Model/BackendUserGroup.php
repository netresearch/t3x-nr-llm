<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

/**
 * Minimal Extbase model for `be_groups`.
 *
 * It exists solely as the concrete target of the {@see LlmConfiguration}
 * `beGroups` MM relation used for per-group access control. TYPO3 core no longer
 * ships an Extbase model for `be_groups` and ext:beuser is not a dependency, so
 * Extbase needs a concrete class it can instantiate when hydrating the relation.
 *
 * Only `uid` (via AbstractEntity) and the human-readable `title` are mapped — no
 * permission masks, allowed-table lists or other authorization-bearing columns
 * are exposed.
 */
class BackendUserGroup extends AbstractEntity
{
    protected string $title = '';

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }
}
