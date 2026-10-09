<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\SkillToolAllowList;
use Netresearch\NrLlm\Service\Option\ToolOptions;

/**
 * @internal Live gates and restored options shared by both resume paths.
 */
final readonly class ToolResumption
{
    /**
     * @param list<string> $offered
     */
    public function __construct(
        public ToolOptions $options,
        public ?RunAugmentation $augmentation,
        public SkillToolAllowList $skillAllowList,
        public SkillToolAllowList $storedAllowList,
        public array $offered,
    ) {}
}
