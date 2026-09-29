<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Decision;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\ValueObject\Decision\SubjectField;
use Netresearch\NrLlm\Exception\NrLlmExceptionInterface;
use RuntimeException;
use Throwable;

/**
 * A decision could not be made (ADR-211). There is no partial or default
 * result: whatever went wrong, the caller holds no answer it could mistake
 * for one.
 *
 * The code names the cause; the constants are stable.
 *
 * @api
 */
final class DecisionException extends RuntimeException implements NrLlmExceptionInterface
{
    public const UNKNOWN_PROFILE = 1795211040;

    public const MISSING_SUBJECT_FIELD = 1795211041;

    public const NO_CONFIGURATION = 1795211042;

    public const UNKNOWN_CONFIGURATION = 1795211043;

    public const DATA_CLASS_NOT_PERMITTED = 1795211044;

    public const MODEL_CANNOT_DECIDE = 1795211045;

    public const FAILED = 1795211046;

    public const INVALID_ANSWER = 1795211047;

    public const NO_SUCH_ANSWER = 1795211048;

    public const REJECTED = 1795211049;

    public const INVALID_PROFILE = 1795211050;

    public static function unknownProfile(string $profile): self
    {
        return new self(sprintf('No decision profile "%s" is declared.', $profile), self::UNKNOWN_PROFILE);
    }

    public static function invalidProfile(string $provider, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('The decision profiles of %s cannot be used: %s', $provider, $reason),
            self::INVALID_PROFILE,
            $previous,
        );
    }

    public static function missingSubjectField(string $profile, SubjectField $field): self
    {
        return new self(
            sprintf('Decision profile "%s" requires the subject field "%s", which the request does not carry.', $profile, $field->value),
            self::MISSING_SUBJECT_FIELD,
        );
    }

    public static function noConfiguration(): self
    {
        return new self(
            'The request names no configuration, and the extension setting decision.configuration names none either.',
            self::NO_CONFIGURATION,
        );
    }

    public static function unknownConfiguration(string $configuration): self
    {
        return new self(
            sprintf('No active configuration "%s" exists.', $configuration),
            self::UNKNOWN_CONFIGURATION,
        );
    }

    public static function dataClassNotPermitted(string $profile, ToolDataClass $dataClass, string $configuration, TrustZone $zone): self
    {
        return new self(
            sprintf(
                'Decision profile "%s" holds %s data, which configuration "%s" may not send: its provider or a fallback sits in the trust zone "%s".',
                $profile,
                $dataClass->value,
                $configuration,
                $zone->value,
            ),
            self::DATA_CLASS_NOT_PERMITTED,
        );
    }

    public static function modelCannotDecide(string $configuration, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Configuration "%s" has no model that can answer decisions: %s', $configuration, $reason),
            self::MODEL_CANNOT_DECIDE,
            $previous,
        );
    }

    /**
     * The provider refused the request as invalid — a subject over its size
     * limit, a question it cannot take. Distinct from a failure: asking again
     * unchanged does not help.
     */
    public static function rejected(string $configuration, Throwable $previous): self
    {
        return new self(
            sprintf('The model of configuration "%s" rejected the request: %s', $configuration, $previous->getMessage()),
            self::REJECTED,
            $previous,
        );
    }

    public static function failed(string $configuration, Throwable $previous): self
    {
        return new self(
            sprintf('The decision on configuration "%s" failed: %s', $configuration, $previous->getMessage()),
            self::FAILED,
            $previous,
        );
    }

    public static function invalidAnswer(string $configuration, string $key, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('The model of configuration "%s" answered question "%s" invalidly: %s', $configuration, $key, $reason),
            self::INVALID_ANSWER,
            $previous,
        );
    }

    public static function noSuchAnswer(string $profile, string $key): self
    {
        return new self(sprintf('Decision profile "%s" has no question "%s".', $profile, $key), self::NO_SUCH_ANSWER);
    }
}
