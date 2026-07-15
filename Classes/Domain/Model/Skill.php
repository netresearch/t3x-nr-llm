<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

use Netresearch\NrLlm\Domain\Enum\SkillTrustLevel;
use Netresearch\NrLlm\Domain\Enum\SupportStatus;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

class Skill extends AbstractEntity
{
    protected int $source = 0;
    protected string $identifier = '';
    protected string $name = '';
    protected string $description = '';
    protected string $body = '';
    protected string $bodyChecksum = '';
    protected string $sourceSha = '';
    protected string $rawFrontmatter = '';
    protected string $supportStatus = SupportStatus::FULL->value;
    protected string $unsupportedNotes = '';
    protected string $allowedTools = '';
    protected string $trustLevel = SkillTrustLevel::UNTRUSTED->value;
    protected string $injectionScan = '';
    protected bool $orphaned = false;
    protected bool $enabled = false;

    public function getSource(): int
    {
        return $this->source;
    }

    public function setSource(int $source): void
    {
        $this->source = $source;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): void
    {
        $this->identifier = $identifier;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
    }

    public function getBodyChecksum(): string
    {
        return $this->bodyChecksum;
    }

    public function setBodyChecksum(string $bodyChecksum): void
    {
        $this->bodyChecksum = $bodyChecksum;
    }

    public function getSourceSha(): string
    {
        return $this->sourceSha;
    }

    public function setSourceSha(string $sourceSha): void
    {
        $this->sourceSha = $sourceSha;
    }

    public function getRawFrontmatter(): string
    {
        return $this->rawFrontmatter;
    }

    public function setRawFrontmatter(string $rawFrontmatter): void
    {
        $this->rawFrontmatter = $rawFrontmatter;
    }

    /**
     * @return array<string,mixed>
     */
    public function getRawFrontmatterArray(): array
    {
        if (trim($this->rawFrontmatter) === '') {
            return [];
        }
        $decoded = json_decode($this->rawFrontmatter, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $k => $v) {
            $out[(string)$k] = $v;
        }
        return $out;
    }

    public function getSupportStatus(): string
    {
        return $this->supportStatus;
    }

    /**
     * Get support status as enum.
     */
    public function getSupportStatusEnum(): ?SupportStatus
    {
        return SupportStatus::tryFrom($this->supportStatus);
    }

    public function setSupportStatus(string $supportStatus): void
    {
        $this->supportStatus = $supportStatus;
    }

    public function getUnsupportedNotes(): string
    {
        return $this->unsupportedNotes;
    }

    public function setUnsupportedNotes(string $unsupportedNotes): void
    {
        $this->unsupportedNotes = $unsupportedNotes;
    }

    public function getAllowedTools(): string
    {
        return $this->allowedTools;
    }

    public function setAllowedTools(string $allowedTools): void
    {
        $this->allowedTools = $allowedTools;
    }

    /**
     * Decode the stored allow-list into tool names.
     *
     * Returns null when the skill expresses no opinion (stored value is the
     * empty string, i.e. the front-matter declared no `allowed-tools` key, or
     * the stored JSON is not an array). Returns the decoded list of string
     * names otherwise — including the empty list `[]`, which is a deliberate
     * fail-closed declaration of "no tools".
     *
     * @return list<string>|null
     */
    public function getAllowedToolsList(): ?array
    {
        if ($this->allowedTools === '') {
            return null;
        }
        $decoded = json_decode($this->allowedTools, true);
        return is_array($decoded) ? array_values(array_filter($decoded, is_string(...))) : null;
    }

    public function getTrustLevel(): string
    {
        return $this->trustLevel;
    }

    /**
     * Trust level as an enum, failing CLOSED to the lowest level for an empty
     * or unrecognised stored value — a skill never reads as more trusted than
     * its denormalised source classification.
     */
    public function getTrustLevelEnum(): SkillTrustLevel
    {
        return SkillTrustLevel::fromStringOrUntrusted($this->trustLevel);
    }

    public function setTrustLevel(string $trustLevel): void
    {
        $this->trustLevel = $trustLevel;
    }

    public function getInjectionScan(): string
    {
        return $this->injectionScan;
    }

    public function setInjectionScan(string $injectionScan): void
    {
        $this->injectionScan = $injectionScan;
    }

    /**
     * Decode the stored prompt-injection scan findings.
     *
     * @return list<array<string,mixed>>
     */
    public function getInjectionFindings(): array
    {
        if (trim($this->injectionScan) === '') {
            return [];
        }
        $decoded = json_decode($this->injectionScan, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $row = [];
            foreach ($entry as $key => $value) {
                $row[(string)$key] = $value;
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Whether the ingest scanner flagged any injection pattern on this body.
     */
    public function hasInjectionFindings(): bool
    {
        return $this->getInjectionFindings() !== [];
    }

    public function isOrphaned(): bool
    {
        return $this->orphaned;
    }

    public function getIsOrphaned(): bool
    {
        return $this->isOrphaned();
    }

    public function setOrphaned(bool $orphaned): void
    {
        $this->orphaned = $orphaned;
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
