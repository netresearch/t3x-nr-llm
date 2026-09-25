<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

use Netresearch\NrLlm\Domain\ValueObject\GlossaryTerms;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

/**
 * Domain model for a translation glossary (ADR-208).
 *
 * One glossary holds the term pairs one site uses when translating from one
 * language into another. The backend module reads it through this model; the
 * translation path reads the table directly
 * ({@see \Netresearch\NrLlm\Service\Glossary\GlossaryResolver}).
 */
class Glossary extends AbstractEntity
{
    protected string $name = '';

    /** Site identifier, as in config/sites/<identifier>. */
    protected string $siteIdentifier = '';

    /** ISO 639-1 base code, lowercase. */
    protected string $sourceLanguage = '';

    /** ISO 639-1 base code, lowercase. */
    protected string $targetLanguage = '';

    /** One term pair per line, see {@see GlossaryTerms::fromText()}. */
    protected string $entries = '';

    /** The TCA enable column, mapped so the list can mark hidden records. */
    protected bool $hidden = false;

    public function getName(): string
    {
        return $this->name;
    }

    public function getSiteIdentifier(): string
    {
        return $this->siteIdentifier;
    }

    public function getSourceLanguage(): string
    {
        return $this->sourceLanguage;
    }

    public function getTargetLanguage(): string
    {
        return $this->targetLanguage;
    }

    public function getEntries(): string
    {
        return $this->entries;
    }

    public function getTerms(): GlossaryTerms
    {
        return GlossaryTerms::fromText($this->entries);
    }

    /**
     * The number of pairs the translation path will use — lines it skips are
     * not counted, so the list shows what actually takes effect.
     */
    public function getTermCount(): int
    {
        return $this->getTerms()->count();
    }

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setSiteIdentifier(string $siteIdentifier): void
    {
        $this->siteIdentifier = $siteIdentifier;
    }

    public function setSourceLanguage(string $sourceLanguage): void
    {
        $this->sourceLanguage = $sourceLanguage;
    }

    public function setTargetLanguage(string $targetLanguage): void
    {
        $this->targetLanguage = $targetLanguage;
    }

    public function setEntries(string $entries): void
    {
        $this->entries = $entries;
    }

    public function setHidden(bool $hidden): void
    {
        $this->hidden = $hidden;
    }
}
