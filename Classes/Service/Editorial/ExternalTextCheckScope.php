<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Editorial;

/**
 * Who and what a check of a delivered text is for (ADR-300).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class ExternalTextCheckScope
{
    /**
     * @param list<int> $furtherConfigurations configurations whose term lists and judge apply too (ADR-290)
     * @param string    $siteIdentifier        selects the term lists bound to a site; '' for none
     * @param string    $language              selects the term lists bound to a language; '' for none
     * @param string    $writerProvider        identifier of the provider that wrote the text; '' takes the
     *                                         provider of the checked configuration, and where that is not
     *                                         known or declares no zone the most trusted zone counts (ADR-288 R10)
     */
    public function __construct(
        public array $furtherConfigurations = [],
        public string $siteIdentifier = '',
        public string $language = '',
        public string $writerProvider = '',
        public int $beUserUid = 0,
        public string $correlationId = '',
    ) {}
}
