<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use InvalidArgumentException;

/**
 * For a {@see RequiresInputInterface} tool whose input schema depends on the
 * call (ADR-214 item 9, the choice builtin): the options a person picks from
 * are the call's arguments, so one schema per tool cannot describe them. It
 * has an effect only on a tool that also implements RequiresInputInterface.
 *
 * {@see ToolLoopService} asks this method instead of
 * {@see RequiresInputInterface::getInputSchema()} when it suspends on such a
 * call, and the suspended state carries the result, so the submission is
 * validated against the schema of the call the person was shown.
 * {@see RequiresInputInterface::getInputSchema()} stays the tool's general
 * shape, for places that ask without a call.
 *
 * Arguments come from the model. A call whose arguments cannot be asked with
 * is the model's mistake, not a programming error: the method throws, the loop
 * does not suspend on that call, and the tool's own execute() refuses the same
 * arguments with an error result.
 *
 * @api Extension point
 */
interface ArgumentInputSchemaInterface
{
    /**
     * The input schema for one call, in the subset of
     * {@see RequiresInputInterface::getInputSchema()}; an `enum` on a property
     * is enforced when the input is submitted.
     *
     * @param array<string, mixed> $arguments the model's arguments of the call
     *
     * @throws InvalidArgumentException when the arguments cannot be asked with
     *
     * @return array<string, mixed>
     */
    public function inputSchemaFor(array $arguments): array;
}
