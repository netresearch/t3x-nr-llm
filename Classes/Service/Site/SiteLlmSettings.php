<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Site;

use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Reads what a site says about its AI calls from the site settings (ADR-244).
 *
 * Both settings hold identifiers: the identifier of an LLM configuration and the
 * identifier of an nr-vault secret. A key itself never sits in a site setting,
 * because `config/sites/<site>/settings.yaml` is versioned with the project.
 *
 * Empty means "the site names none", and so does an unknown site: the caller
 * then uses the installation's choice. The site is looked up by identifier, not
 * from the request, so a CLI worker reads the same value as a backend request.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class SiteLlmSettings
{
    /** Identifier of the LLM configuration the site's calls use. */
    public const CONFIGURATION = 'nrllm.configuration';

    /** nr-vault identifier of the DeepL key the site's translations use. */
    public const DEEPL_API_KEY_IDENTIFIER = 'nrllm.deepl.apiKeyIdentifier';

    public function __construct(
        private SiteFinder $siteFinder,
    ) {}

    public function configurationIdentifier(?string $siteIdentifier): string
    {
        return $this->value($siteIdentifier, self::CONFIGURATION);
    }

    public function deeplApiKeyIdentifier(?string $siteIdentifier): string
    {
        return $this->value($siteIdentifier, self::DEEPL_API_KEY_IDENTIFIER);
    }

    private function value(?string $siteIdentifier, string $key): string
    {
        if ($siteIdentifier === null || $siteIdentifier === '') {
            return '';
        }

        try {
            $value = $this->siteFinder->getSiteByIdentifier($siteIdentifier)->getSettings()->get($key);
        } catch (SiteNotFoundException) {
            return '';
        }

        return is_string($value) ? trim($value) : '';
    }
}
