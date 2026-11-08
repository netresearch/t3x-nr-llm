<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->

# A tool call's arguments are checked against its schema before the turn runs

> Current contract: [L02](../current-contract.md#l02-lenient-argument-acceptance-does-not-preserve-boolean-meaning). These named amendments supersede conflicting historical increment wording below. [Inventory](../README.md).

A model that sends arguments that do not fit a tool's schema hears which ones and corrects them in the next round; nothing of that turn runs (ADR-246).

## Required behavior

1. Before any call of a turn runs, the tool loop checks each call of an offered tool against the `parameters` schema that tool was offered with in this run.
2. The check refuses a missing required argument, null for a required argument whose schema declares types without `null`, a value of the wrong kind, a value outside an `enum`, and an argument excluded by `additionalProperties: false`, recursing into `properties` and `items`. It names each mismatch in one sentence with the argument's path, at most ten.
3. It accepts what a tool commonly reads after a cast: numeric text and integral floats for an integer, numbers for text, `"true"`, `"false"`, `0` and `1` for a boolean, null for an optional argument and for a required one whose schema admits it, a number for a text enum value, an enum value in another letter case.
4. It does not judge a schema node with `$ref`, `oneOf`, `anyOf`, `allOf`, `not` or `if`, nor an unknown `type` name.
5. A turn with at least one mismatch runs no tool, raises no approval or input pause, charges no external call budget and appends nothing to the invocation history. The mismatched call is answered with its mismatches, every other call of the turn with "Not run".
6. Each answered call is recorded as a failed tool step with `toolRefusal` `arguments` or `turn`; the code survives the metadata-only privacy level.

## Not done

No coercion of arguments. No check of a tool that was not offered (the loop refuses it by name, as before). No check on the resume of an approved or answered call, because a turn with a mismatch never pauses. No per-tool opt-out.

## Boundaries

The check reads the schema the run offered, including a server-supplied MCP schema; it never fetches one. Its answer goes to the model and the run trace only. The `@api` surface grows by `RunTrace::recordToolRefusal()`; additive.

## Tests

- Unit `ToolArgumentCheckerTest`, requirements 2, 3 and 4.
- Unit `ToolLoopServiceArgumentCheckTest`, requirements 1, 5 and 6, and that a corrected call runs in the next round.
- Unit `RunStepTest::onlyAToolStepStatesARefusal`, requirement 6.
