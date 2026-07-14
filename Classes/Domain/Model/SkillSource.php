<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

use Netresearch\NrLlm\Domain\Enum\SkillSourceType;
use Netresearch\NrLlm\Domain\Enum\SkillTrustLevel;
use Netresearch\NrLlm\Domain\Enum\SyncStatus;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

class SkillSource extends AbstractEntity
{
    protected string $title = '';
    protected string $type = SkillSourceType::SINGLE_FILE->value;
    protected string $url = '';
    protected string $ref = '';
    protected string $pinnedSha = '';
    protected string $githubToken = '';
    protected string $trustLevel = SkillTrustLevel::UNTRUSTED->value;
    protected string $expectedFingerprint = '';
    protected string $syncStatus = SyncStatus::NEVER_SYNCED->value;
    protected string $syncError = '';
    protected int $lastSynced = 0;
    protected bool $enabled = true;

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get type as enum.
     */
    public function getTypeEnum(): ?SkillSourceType
    {
        return SkillSourceType::tryFrom($this->type);
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
    }

    public function getRef(): string
    {
        return $this->ref;
    }

    public function setRef(string $ref): void
    {
        $this->ref = $ref;
    }

    public function getPinnedSha(): string
    {
        return $this->pinnedSha;
    }

    public function setPinnedSha(string $pinnedSha): void
    {
        $this->pinnedSha = $pinnedSha;
    }

    public function getGithubToken(): string
    {
        return $this->githubToken;
    }

    public function setGithubToken(string $githubToken): void
    {
        $this->githubToken = $githubToken;
    }

    public function getTrustLevel(): string
    {
        return $this->trustLevel;
    }

    /**
     * Trust level as an enum, failing CLOSED to the lowest level for an empty
     * or unrecognised stored value.
     */
    public function getTrustLevelEnum(): SkillTrustLevel
    {
        return SkillTrustLevel::fromStringOrUntrusted($this->trustLevel);
    }

    public function setTrustLevel(string $trustLevel): void
    {
        $this->trustLevel = $trustLevel;
    }

    public function getExpectedFingerprint(): string
    {
        return $this->expectedFingerprint;
    }

    public function setExpectedFingerprint(string $expectedFingerprint): void
    {
        $this->expectedFingerprint = $expectedFingerprint;
    }

    public function getSyncStatus(): string
    {
        return $this->syncStatus;
    }

    /**
     * Get sync status as enum.
     */
    public function getSyncStatusEnum(): ?SyncStatus
    {
        return SyncStatus::tryFrom($this->syncStatus);
    }

    public function setSyncStatus(string $syncStatus): void
    {
        $this->syncStatus = $syncStatus;
    }

    public function getSyncError(): string
    {
        return $this->syncError;
    }

    public function setSyncError(string $syncError): void
    {
        $this->syncError = $syncError;
    }

    public function getLastSynced(): int
    {
        return $this->lastSynced;
    }

    public function setLastSynced(int $lastSynced): void
    {
        $this->lastSynced = $lastSynced;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getIsEnabled(): bool
    {
        return $this->isEnabled();
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }
}
