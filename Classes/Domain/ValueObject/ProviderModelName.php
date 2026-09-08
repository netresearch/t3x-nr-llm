<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Stringable;

/**
 * The name a provider's API knows a model by: `gpt-image-2`, `dall-e-3` (#893).
 *
 * What {@see \Netresearch\NrLlm\Domain\Model\Model::getModelId()} returns, what
 * the `model_id` column stores, what goes into a request body, and what
 * {@see \Netresearch\NrLlm\Domain\Repository\ModelRepository::findOneByModelId()}
 * looks a record up by.
 *
 * Named for what it is rather than after the column, because `ModelId` next to
 * {@see ModelIdentifier} would be two type names a reader has to keep apart by
 * suffix alone -- which is how the confusion this type ends came about. The
 * getters keep their names: `Model::getModelId()` is `@api`.
 *
 * Unlike a row identifier this one is not unique: the same API model offered
 * through two providers is two rows carrying the same name, and the `model_id`
 * column has no `unique` eval. Which row a lookup by this name returns is
 * therefore a question this type does not answer -- see #935.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class ProviderModelName implements Stringable
{
    /** Normalized: never blank, never padded. */
    public string $value;

    public function __construct(string $value)
    {
        // Trimmed, not merely checked: this value also goes into a request
        // body, where a padded model name is rejected by the provider rather
        // than silently ignored.
        $normalized = trim($value);
        if ($normalized === '') {
            throw new InvalidArgumentException('A provider model name cannot be blank.', 1788300005);
        }

        $this->value = $normalized;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
