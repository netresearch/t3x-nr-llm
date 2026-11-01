<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

use Netresearch\NrLlm\Domain\ValueObject\TermLines;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

use function count;

/**
 * A term list as the backend module lists it (ADR-238).
 *
 * The guardrail does not read through here:
 * {@see \Netresearch\NrLlm\Service\Guardrail\Term\TermListResolver} does, with
 * the enable fields a list in this module ignores.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
class TermList extends AbstractEntity
{
    protected string $name = '';

    /** CSV of tx_nrllm_configuration uids */
    protected string $configurations = '';

    protected string $siteIdentifier = '';

    protected string $language = '';

    protected string $forbiddenTerms = '';

    protected string $requiredTerms = '';

    protected bool $hidden = false;

    public function getName(): string
    {
        return $this->name;
    }

    public function getSiteIdentifier(): string
    {
        return $this->siteIdentifier;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getConfigurationCount(): int
    {
        return count(array_filter(explode(',', $this->configurations)));
    }

    public function getForbiddenCount(): int
    {
        return count(TermLines::parse($this->forbiddenTerms));
    }

    public function getRequiredCount(): int
    {
        return count(TermLines::parse($this->requiredTerms));
    }

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setConfigurations(string $configurations): void
    {
        $this->configurations = $configurations;
    }

    public function setSiteIdentifier(string $siteIdentifier): void
    {
        $this->siteIdentifier = $siteIdentifier;
    }

    public function setLanguage(string $language): void
    {
        $this->language = $language;
    }

    public function setForbiddenTerms(string $forbiddenTerms): void
    {
        $this->forbiddenTerms = $forbiddenTerms;
    }

    public function setRequiredTerms(string $requiredTerms): void
    {
        $this->requiredTerms = $requiredTerms;
    }

    public function setHidden(bool $hidden): void
    {
        $this->hidden = $hidden;
    }
}
