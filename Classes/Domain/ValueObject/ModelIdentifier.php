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
 * The identifier of a `tx_nrllm_model` row: `gpt-image-2-a3f7c2` (#893).
 *
 * What {@see \Netresearch\NrLlm\Domain\Model\Model::getIdentifier()} returns,
 * and what {@see \Netresearch\NrLlm\Domain\Repository\ModelRepository::findOneByIdentifier()}
 * looks a record up by.
 *
 * Its counterpart is {@see ProviderModelName}, the name the provider's API
 * knows the model by. A model row carries both, and the wizard makes them
 * differ by construction: `generateIdentifier()` mints `<slug>-<6 hex>` for
 * the row while `model_id` keeps the plain API string. That is the same
 * two-namespaces-behind-similar-names shape as `ProviderAdapterKey` and
 * `ProviderIdentifier`, and it has already shipped once as a defect -- the
 * cost calculator looked a curated row up in this column with an API model
 * name, found nothing, and silently priced from the static catalog instead
 * (#932).
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class ModelIdentifier implements Stringable
{
    /** Normalized: never blank, never padded. */
    public string $value;

    public function __construct(string $value)
    {
        // Trimmed, not merely checked: the padded form is not blank, so it
        // would pass the guard and then match no row -- the failure this
        // type exists to prevent, arriving through the door left open by
        // validating one string and storing another.
        $normalized = trim($value);
        if ($normalized === '') {
            throw new InvalidArgumentException('A model identifier cannot be blank.', 1788300004);
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
