<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Exception;

/**
 * Exception thrown when a speech service operation fails.
 *
 * This covers errors from speech-to-text (Whisper) and
 * text-to-speech (TTS) services.
 */
final class SpeechServiceException extends SpecializedServiceException {}
