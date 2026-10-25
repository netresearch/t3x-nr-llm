<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Whether a skill works on one page: the frontmatter's ``page`` (ADR-229).
 *
 * User-interface metadata for a consumer such as a chat, never part of the
 * skill's version. A skill that declares nothing has no value; what that
 * means is the consumer's default (ADR-229).
 *
 * @api
 */
enum SkillPageNeed: string
{
    /**
     * The skill works on one page and needs it.
     */
    case REQUIRED = 'required';

    /**
     * The skill may work on a page.
     */
    case OPTIONAL = 'optional';

    /**
     * The skill does not work on a page.
     */
    case NONE = 'none';
}
