<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# A non-admin run reads the metadata of a file it may access

Governing decision: ADR-303. `read_fal_asset_meta` is admin-only (ADR-047), so a
run acting for an editor cannot learn the title, the alternative text or the
copyright notice of a file the editor sees in the file list. `search_fal_files`
answers for non-admins but returns name, MIME type and size only. This adds one
read tool, `describe_fal_asset`, that answers for the acting user with the
permissions the backend applies when it shows the same file.

## Requirements

- **DF1 Tool.** A builtin named `describe_fal_asset`, read-only
  (`ToolEffect::READ_ONLY`), in the `files` group, enabled by default, not
  admin-only, with the `files` group's data class (no override).
- **DF2 One file.** It takes one argument, `uid`, the `sys_file` uid. A uid
  below 1 is answered with the neutral denial of DF3.
- **DF3 The acting user decides, and nothing leaks.** Access is
  `FalStorageGate::isFileAccessible()` for the run's acting backend user
  (`ToolExecutionContext::actingBackendUser()`), never `$GLOBALS['BE_USER']`:
  the configured storage allow-list, for a non-admin intersected with the
  user's storages and file mounts. A file outside them, a uid no file carries,
  and a run without a backend user receive the same answer, byte for byte —
  `Asset not found or not permitted.`, the sentence the sibling FAL tools use.
- **DF4 Fields.** Always `title`, `alternative`, `description`, `width` and
  `height` (core). `copyright`, `creator`, `source`, `publisher` and `language`
  only where the live TCA declares the column
  (`$GLOBALS['TCA']['sys_file_metadata']['columns'][…]`, i.e. EXT:filemetadata
  is loaded); otherwise the line is absent and no query names the column.
- **DF5 Not set is said.** An empty or missing text, a width or height of 0, and
  an accessible file without a metadata record are reported as the bare marker
  `not set`. The file name and every stored text are reported as JSON string literals, line breaks and C1 controls escaped, so a value
  cannot be mistaken for a marker and a line break inside it cannot start a
  forged line. Nothing is inferred: usage rights are never derived from the
  file name, and the tool description says that `not set` means unknown.
- **DF6 Field permission.** A column whose TCA carries `exclude` (core:
  `title`; EXT:filemetadata: all five of DF4) is reported to a non-admin only
  with the `non_exclude_fields` grant `sys_file_metadata:<column>`; without it
  the line is the bare marker `withheld (no field permission)` and the column
  is not selected. Admins see every column. No `tables_select` grant on
  `sys_file_metadata` is required: core's own read-only file view
  (`ElementInformationController`) gates a file by its `read` permission and its
  metadata by `non_exclude_fields` only, and this tool mirrors it.
- **DF7 Which row.** The default-language row (`sys_language_uid = 0`), as the
  run's workspace shows it (ADR-263): live, or in a run started in a draft
  workspace its version there. Never another workspace's draft. No language
  argument.

## Out of scope

- A usage-rights table or any rights decision (a later package).
- Any change to `read_fal_asset_meta`: it stays admin-only and keeps its own
  storage list, because opening it would also change who reads metadata in the
  backend chat.
- Translated metadata rows, a language argument, categories, keywords, captions
  and the other EXT:filemetadata columns.
- Creating a metadata record, or writing anything.
- Gating `search_fal_files`, which still matches on `title` without the
  `non_exclude_fields` grant (recorded in ADR-303 as a known difference).

## Verification

| Requirement | Evidence |
| --- | --- |
| DF1 | `FalToolsSpecTest`, `BuiltinToolGroupsTest`, functional `DescribeFalAssetToolTest::readFalAssetMetaRefusesTheEditorWhomDescribeFalAssetAnswers` (through `ToolCallPolicy`) |
| DF2, DF3 | functional `DescribeFalAssetToolTest`: `anEditorReadsTheMetadataOfAFileInsideTheirMount`, `aFileOutsideTheMountAndAMissingUidGetTheSameBytes`, `theActingUserDecidesNotTheAmbientOne`, unit `FalToolsSpecTest::failClosedWithoutBackendUser` |
| DF4 without EXT:filemetadata | functional `DescribeFalAssetToolTest::withoutExtFilemetadataNoCopyrightLineAndNoError` |
| DF4 with EXT:filemetadata, DF5 | functional `DescribeFalAssetFilemetadataTest` |
| DF5 | functional `DescribeFalAssetToolTest::anAccessibleFileWithoutMetadataIsNotSetEverywhere`, `aStoredValueCannotForgeALine` |
| DF6 | functional `DescribeFalAssetToolTest::aFieldWithoutTheGrantIsWithheld`, `DescribeFalAssetFilemetadataTest::theFilemetadataColumnsNeedTheirGrant` |
| DF7 | functional `DescribeFalAssetToolTest::anotherWorkspacesDraftIsNeverRead`, `aTranslationIsNeverReadInsteadOfTheDefaultRow` |
