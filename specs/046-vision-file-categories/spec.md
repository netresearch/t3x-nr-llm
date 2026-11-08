<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# A file is tagged from a configured category tree

> Current contract: [L07](../current-contract.md#l07-category-limits-need-overflow-and-boundary-acceptance-rules). These named amendments supersede conflicting historical increment wording below. [Inventory](../README.md).

Editors tag images with categories, and no tool here writes a file's
categories. A vision editor action should propose them from the image, from a
part of the category tree an administrator chooses. Decision record: ADR-268.

## What it must do

- Add the writing tool `set_file_categories`: one file's default-language
  metadata record, through the DataHandler, as the acting user, in the run's
  workspace; disabled by default, group `editing`, approval for every call.
- Read the allowed categories at call time: live, not hidden, not deleted,
  default language, below `vision.categories.rootUid` (not the root itself), at
  most ten levels and 200 entries, inside the acting user's category mounts
  where they have any.
- Refuse before the approval card a call naming a category outside that list,
  naming the list; refuse every call when no root is configured.
- Keep categories the file carries from outside the list; the call sets the
  list's part of the field, an empty set removes only that part.
- Read the relation back after the write, in the user's workspace, and report
  a write that did not take.
- Show the titles before and after on the card, and both uid lists in its
  technical details.
- Declare the editor action with the vision field `categories`; the field is
  off without a root, so the action is not offered then. The vision prompt
  names the allowed categories as `uid: title`.
- Count a numeric field as empty at `NULL` or `0` in the folder batch.

## What it explicitly does not do

- No new categories, no translated metadata records, no tags on file
  references.
- No TSconfig form of the root, no several roots.
- No crop suggestions, variants, upscaling or outpainting.

## Which suite proves each requirement

| Requirement | Suite |
|-------------|-------|
| Malformed arguments, no root, no acting user, declaration, group, effect, pending target | unit: `SetFileCategoriesToolTest` |
| Field off without a root, root parsing | unit: `VisionSettingsTest` |
| List contents and order, category mounts, write keeps other trees, empty set, refusal before the card, neutral refusal outside the mount, German card, draft workspace version, prompt list | functional: `SetFileCategoriesToolTest` |
| Relation field empty at zero | functional: `BackgroundAltTextTest` |
| Registered tool, icon, heading, pending target, counts on every surface | unit/functional: `BuiltinToolGroupsTest`, `EditorActionDeclarationTest`, `EditorActionIconRegistrationTest`, `CurrentColorIconMarkupTest`, `ApprovalPreviewHeadingsTest`, `PendingTargetCoverageTest`, `ProductFactsConsistencyTest` |
