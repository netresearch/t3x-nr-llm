<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->

# A page or a content element knows that an AI wrote it

A record an agent run wrote carries a field that a template can read, and an editor can clear (ADR-239).

## Required behavior

1. `pages` and `tt_content` carry `tx_nrllm_ai_written`. It is an excluded check field on the access tab, default off.
2. A write that creates or updates a row of these tables through a tool sets the field. A move, a publish, a copy and a deletion do not, and a write to another table changes nothing.
3. A fill run (ADR-237) that writes a field announces the write and sets the field. One that wrote nothing does not.
4. Setting the field goes straight to the database: no history entry, no `tstamp` change, no second DataHandler run, no new fill run.
5. An editor who clears the field keeps it cleared until the next AI write, and clearing it starts no fill run.
6. A failing listener does not fail the write (ADR-187).

## Not done

No frontend output: what a site says about a record stays the site's decision, and the field is what a template reads. nr-landingpage renders its own label from its own generation data. No field on any other table. Rewriting the text by hand does not clear the field. No backfill of records written before this change.

## Boundaries

The two columns need the database schema update. Until then new pages and content elements cannot be saved, as with any column added to these tables.

## Tests

- Functional `MarkAiWrittenRecordTest`, requirements 1, 2, 4 and 5, through a real database.
- Unit `ToolLoopServiceWriteEventTest` already pins requirement 6 for tools. Functional `FieldFillTest`, requirements 3 and 6.
