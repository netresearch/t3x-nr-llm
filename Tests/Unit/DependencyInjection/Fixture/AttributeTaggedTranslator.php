<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\DependencyInjection\Fixture;

use Netresearch\NrLlm\Attribute\AsTranslator;

/**
 * Test fixture: a class carrying the #[AsTranslator] attribute.
 *
 * Used to exercise the attribute-discovery path in TranslatorCompilerPass
 * without pulling in a real translator (with its HTTP client / LLM
 * manager dependencies). The compiler pass under test runs with an
 * overridden scan-namespace prefix so this fixture is matched.
 */
#[AsTranslator]
final class AttributeTaggedTranslator {}
