<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->

# Another extension reads an editor's budget status through the API

The read model behind the budget panel of the AI Tasks module (spec 027) is `@internal`. Other extensions show the same status to the same editor and call it directly, so an internal change in a patch release breaks them without a version conflict. The read model becomes `@api` (ADR-232).

## Required behavior

1. `UserBudgetStatusService`, `UserBudgetStatus`, `BudgetWindowStatus`, `BudgetMeter` and `LocalizedNumberFormatter` carry `@api`. The change to `Tests/Unit/Api/api-surface.txt` is additive: five classes added, nothing removed or changed.
2. Every `Netresearch\NrLlm` type in a method or property signature of the five is `@api`. The constructor of `UserBudgetStatusService` is frozen but out of contract (DI only), so its parameter types stay `@internal`.
3. No behaviour and no signature changes.

## Not done

No interface for `UserBudgetStatusService`, no change to `BudgetMeter::$formatter` or to the array `BudgetWindowStatus::fromUsage()` takes, no new read path.

## Tests

- Unit `ApiSurfaceSnapshotTest::renderedApiSurfaceMatchesTheCommittedSnapshot`: the five classes are in the snapshot; reverting a marker fails it.
- Unit `ApiSurfaceSnapshotTest::everyTypeInAnApiSignatureIsItselfApi`: the closure holds; reverting the marker of `LocalizedNumberFormatter` fails it with the three signatures that mention it.
- The existing unit and functional tests of the budget panel stay green, which shows that nothing else changed.
