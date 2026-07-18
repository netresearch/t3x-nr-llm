<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Exception;

/**
 * Thrown when a provider rejects the request with HTTP 401 (invalid or missing
 * API key / unauthorized).
 *
 * A status-specific subclass of {@see ProviderResponseException} (ADR-080) so a
 * caller can `catch (ProviderAuthenticationException)` instead of inspecting the
 * message text or the raw `httpStatus`. All typed fields (`httpStatus`,
 * `responseBody`, `endpoint`) and `getCode() === 401` are inherited unchanged,
 * so existing `catch (ProviderResponseException)` handlers keep matching.
 */
final class ProviderAuthenticationException extends ProviderResponseException {}
