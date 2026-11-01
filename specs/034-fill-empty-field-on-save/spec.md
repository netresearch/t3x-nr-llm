<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->

# An empty field is filled by AI after a save

An installation names fields such as `pages.description`. When an editor saves a record and such a field is empty, a background run writes it (ADR-237).

## Required behavior

1. `fieldFill.fields` is empty by default, and then nothing happens on a save.
2. A save queues one tool-free run per empty, opted-in field, under the origin `fill:<table>:<uid>:<field>`. No model is called in the request, and no failure of this feature fails the save.
3. No run is queued for: a field with a value; a field the save itself emptied; an open run of the same field; a workspace; `_cli_`; an import; a copy or a translation made by core; an AI write; a user who may not use the default configuration or may not edit the field; a record with too little text to write from; a save that changes only a slug, a value translations inherit or bookkeeping columns. One request queues at most `FieldFillHook::MAX_RUNS_PER_SAVE` runs.
4. Only plain text fields of content tables count, whatever the setting lists.
5. The worker writes through the DataHandler as the user who saved, and only while the field is empty. A text added in the meantime stands.
6. A failed run writes nothing. An answer that is empty, `NOTHING` or too long writes nothing. Withdrawing the opt-in stops queued runs.
7. The detail page of a run says what became of the answer. The strings exist in English and German.
8. The worker leaves `$GLOBALS['BE_USER']` and `$GLOBALS['LANG']` as it found them.

## Not done

No per-page-tree setting. Saving a content element does not fill its page. No backfill of existing records; `nrllm:editor-action:batch` does that as drafts (ADR-236).

## Boundaries

The text of the record leaves the installation, to the provider of the default configuration. Budget and guardrails apply as for any run of that user. The new column `outcome` of `tx_nrllm_agentrun` needs the database schema update.

## Tests

- Functional `FieldFillTest`, requirements 1, 2, 3, 5, 6 and 8: the hook through a real DataHandler, the worker through `runQueued()`.
- Unit `FieldFillSettingsTest`, requirements 1 and 4. Unit `FieldFillTargetTest`, requirement 2.
- Functional `AgentRunControllerTest` and the XLF checks, requirement 7.
