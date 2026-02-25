<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Exception;

/**
 * Exception thrown when a translation operation fails.
 *
 * This covers errors from specialized translators like DeepL,
 * as well as LLM-based translation failures.
 */
final class TranslatorException extends SpecializedServiceException {}
