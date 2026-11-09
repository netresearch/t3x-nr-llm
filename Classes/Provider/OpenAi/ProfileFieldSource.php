<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\OpenAi;

/**
 * Which source decided one field of a model record's reasoning profile
 * (ADR-274), in the order they are consulted.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
enum ProfileFieldSource: string
{
    /**
     * The operator set the field on the model record.
     */
    case Operator = 'operator';

    /**
     * The bundled measured table has a row for the model id.
     */
    case Table = 'table';

    /**
     * The provider's own model list stated it.
     */
    case Discovery = 'discovery';

    /**
     * A third party's public catalogue stated it.
     */
    case ThirdPartyCatalog = 'third_party_catalog';

    /**
     * The family default in code, or the unknown profile.
     */
    case FamilyDefault = 'default';
}
