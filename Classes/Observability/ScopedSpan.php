<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Observability;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

/**
 * A span that is current until it ends (ADR-291), as {@see GenAiTelemetry::begin()}
 * hands it to code that wraps a piece of its own in one.
 *
 * Ending is the only operation, and it cannot throw: what the instrumentation
 * does must never reach the code it observes.
 */
final readonly class ScopedSpan
{
    public function __construct(
        private SpanInterface $span,
        private ?ScopeInterface $scope,
    ) {}

    /**
     * Ends the span: sets the attributes, marks an error, leaves the scope.
     *
     * @param array<non-empty-string, bool|int|float|string> $attributes
     * @param ?string                                        $errorType  the class name of the exception, or another
     *                                                                   low-cardinality identifier, never a message
     */
    public function finish(array $attributes = [], ?string $errorType = null): void
    {
        try {
            $this->scope?->detach();
        } catch (Throwable) {
            // A scope that cannot be left is no reason to fail the call.
        }

        try {
            $this->span->setAttributes($attributes);
            self::markError($this->span, $errorType);
            $this->span->end();
        } catch (Throwable) {
            // Nor is a span that cannot be written.
        }
    }

    /**
     * The error of a span is its status and `error.type`. The exception
     * message is not recorded, because it can carry fragments of the request.
     */
    public static function markError(SpanInterface $span, ?string $errorType): void
    {
        if ($errorType === null || $errorType === '') {
            return;
        }

        $span->setAttribute(GenAiSemconv::ATTR_ERROR_TYPE, $errorType);
        $span->setStatus(StatusCode::STATUS_ERROR);
    }
}
