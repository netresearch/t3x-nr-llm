<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Exception;

/**
 * Thrown when a provider throttles the request with HTTP 429 (rate limit /
 * quota exceeded).
 *
 * A status-specific subclass of {@see ProviderResponseException} (ADR-080) so a
 * caller can `catch (ProviderRateLimitException)` instead of inspecting the
 * message text or the raw `httpStatus`. All typed fields (`httpStatus`,
 * `responseBody`, `endpoint`) are inherited unchanged, and — critically —
 * `getCode() === 429` is preserved, so the retry/fallback and circuit-breaker
 * middleware that key off code 429 keep firing.
 */
final class ProviderRateLimitException extends ProviderResponseException {}
