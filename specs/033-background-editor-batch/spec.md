<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->

# An editor action runs over a folder or a page tree in the background

The batch of the Editor Action Center runs inside one backend request and stops at 20 records (ADR-162). An editor or a scheduled job now queues one action over a folder or a page tree, and a scheduler run produces alternative texts for a folder from the image content (ADR-236).

## Required behavior

1. `nrllm:editor-action:batch <action> --user=<username> --folder=<id>` or `--page-tree=<uid> [--depth=N]` queues one run per record. The batch page takes the same scope. `--only-empty=<column>` leaves out records holding a value; a vision action does that by itself in the fields it fills.
2. Each record still gets its own catalogue call, as the acting user. A batch is N ordinary runs: no shared approval, no shared digest.
3. No image is read when the batch is queued. A queued run names its file, and the worker reads the image as the acting user. A file that can no longer be sent fails its run, and no model is asked.
4. A batch writes nothing. Every run suspends for its own approval. The batch relates to "nothing is saved without confirmation" as a draft: the text exists on the card, the field stays as it was.
5. A repeated batch skips a record with a value, an open run or a run that ended in a decision, so a rejected card does not come back. A run that failed, was cancelled or ran out of budget decided nothing. At most `EditorActionBatchPlanner::MAX_BACKGROUND_RECORDS` runs of one action are open at a time; a record the catalogue refuses takes no place, and the rest is reported and picked up by the next batch.
6. `--user` must name an active backend user and cannot name `_cli_`. An action, a scope or an `--only-empty` field that cannot be read is refused, and that is told apart from an empty scope. When every record is refused the command says so.
7. Whether the actor may use the default configuration is asked of the actor, not of the ambient user.
8. The strings of the page exist in English and German.

The change to `Tests/Unit/Api/api-surface.txt` is additive: `AgentRunRequest::$imageFileUid`, `withMessage()`, `AiActorContext::fromBackendUser()`.

## Not done

No record picker. No direct write, which ADR-134 forbids a tool and ADR-133 forbids a batch verdict to imply. No change to the synchronous batch of 20.

## Boundaries

An image leaves the installation as in ADR-235, from the worker. The acting user is a person's frozen identity in the queued request.

## Tests

- Functional `BackgroundAltTextTest`, requirements 1 to 7: the whole way, from the command through a worker tick to the image in the model's input and an approved write.
- Functional `EditorActionControllerTest`, requirements 1, 2 and 8: the page plans and queues.
- Functional `FalStorageGateActingUserTest`, requirement 3: a file that can be read need not be editable.
- Unit `EditorActionCatalogueTest`, requirements 2, 3 and 7. Unit `AiActorContextTest` and `ApiSurfaceSnapshotTest`, the public surface.
