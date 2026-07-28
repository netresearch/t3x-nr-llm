<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Preset;

/**
 * What an administrator has to do to make an unsatisfiable preset importable.
 *
 * "Not satisfiable — missing: capabilities: chat, vision" states the diagnosis
 * and leaves the operator to guess the cure: another provider? a model that is
 * merely switched off? a capability nobody ticked? These cases need different
 * actions, and they are distinguishable from the records already present, so
 * the preflight names the action rather than only the symptom.
 *
 * The value is the suffix of the `configuration.presets.remedy.<value>` label.
 */
enum PresetRemedy: string
{
    /**
     * A model matching every criterion exists but is switched off. The
     * cheapest possible fix — one toggle in the Models module.
     */
    case ActivateModel = 'activateModel';

    /**
     * Providers are configured, but none of their models declares the
     * required capabilities. Either the capability is genuinely absent, or a
     * model that has it never got the checkbox — both are fixed in the Models
     * module, not by adding a provider.
     */
    case AddModel = 'addModel';

    /**
     * No active provider at all. Nothing else can be diagnosed until one
     * exists.
     */
    case AddProvider = 'addProvider';

    /**
     * Models cover the capabilities, but a secondary criterion — adapter
     * type, context length, input cost — rules every one of them out. The
     * preset's own requirement is the thing to reconsider here, so this is
     * the one case where the answer may be "not with this setup".
     */
    case AdjustSetup = 'adjustSetup';
}
