<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Skill\Fixtures;

use RuntimeException;

/**
 * Controlled fault after actual transactional writes, before release.
 */
final class ControlledPublicationFailure extends RuntimeException {}
