<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail\Term;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

use function array_slice;
use function implode;
use function sprintf;
use function trim;

/**
 * The sentences an editor reads when a term list blocks an output (ADR-238).
 *
 * In the language of the acting backend user. A queue worker has no ambient
 * user, so {@see self::inLanguage()} names the one the call belongs to; without
 * either the sentence is English. They name terms and the list, never the
 * generated text, because the same sentence is stored as the governance event's
 * detail (ADR-064).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class TermListMessages
{
    private const LL = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:';

    /** The sentence is stored as well, and an editor fixes a few terms at a time. */
    private const MAX_TERMS_NAMED = 20;

    public function __construct(
        private LanguageServiceFactory $languageServiceFactory,
        private string $languageOfTheCall = '',
    ) {}

    /**
     * These sentences for a call whose person has this backend language. An
     * ambient backend user still decides.
     */
    public function inLanguage(string $language): self
    {
        return new self($this->languageServiceFactory, $language);
    }

    /**
     * @param list<string> $terms
     */
    public function forbidden(array $terms, TermListRule $list): string
    {
        return $this->withListMessage(sprintf(
            $this->label('termList.blocked.forbidden', 'This text was not delivered. It contains terms the list "%1$s" does not allow: %2$s.'),
            $list->name,
            implode(', ', array_slice($terms, 0, self::MAX_TERMS_NAMED)),
        ), $list);
    }

    /**
     * @param list<string> $terms
     */
    public function required(array $terms, TermListRule $list): string
    {
        return $this->withListMessage(sprintf(
            $this->label('termList.blocked.required', 'This text was not delivered. It lacks terms the list "%1$s" requires: %2$s.'),
            $list->name,
            implode(', ', array_slice($terms, 0, self::MAX_TERMS_NAMED)),
        ), $list);
    }

    public function checkFailed(): string
    {
        return $this->label('termList.blocked.checkFailed', 'This text was not delivered, because the term check could not run. Please try again. If this persists, ask an administrator to look at the system log.');
    }

    private function withListMessage(string $sentence, TermListRule $list): string
    {
        return trim($sentence . ' ' . $list->message);
    }

    private function label(string $key, string $fallback): string
    {
        $label = $this->language()->sL(self::LL . $key);

        return $label !== '' ? $label : $fallback;
    }

    private function language(): LanguageService
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication && $this->languageOfTheCall !== '') {
            return $this->languageServiceFactory->create($this->languageOfTheCall);
        }

        return $this->languageServiceFactory->createFromUserPreferences(
            $user instanceof BackendUserAuthentication ? $user : null,
        );
    }
}
