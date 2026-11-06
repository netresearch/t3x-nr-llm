<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

// An installation that does not version tt_content: the schema then has no t3ver
// columns on it (ADR-266).
$tca        = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
$definition = is_array($tca['tt_content'] ?? null) ? $tca['tt_content'] : [];
$ctrl       = is_array($definition['ctrl'] ?? null) ? $definition['ctrl'] : [];

$ctrl['versioningWS'] = false;
$definition['ctrl']   = $ctrl;
$tca['tt_content']    = $definition;
$GLOBALS['TCA']       = $tca;
