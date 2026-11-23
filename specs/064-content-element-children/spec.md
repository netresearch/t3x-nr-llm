<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# A content element is drafted with its child records

Governing decision: ADR-324 (amends ADR-146, ADR-196, ADR-198 and ADR-205). Tickets NEXT-228
(the assistant cannot draft accordion, card group, icon group, text with
image, image, uploads) and NEXT-229 (nr_landingpage's Page Composer creates
those elements without items).

## Requirements

- **CC1 Children are a column kind.** An `inline` column with a plain
  one-to-many relation (`foreign_table` + `foreign_field`; no MM, no
  `foreign_table_field`, `foreign_match_fields`, `foreign_selector` or
  `foreign_unique`, not `readOnly`, no `behaviour.enableCascadingDelete =
  false`; the child table has TCA
  and a form and is not `tt_content`, `pages`, `sys_file_reference`, a
  `sys_` or `be_` table) no longer excludes its content type. One level
  deep; a child's own relations, FlexForms and inline columns stay empty.
  The child table is bounded like ADR-197's generic creator (no system or
  sensitive table, nothing `adminOnly` or `readOnly`), `hideTable` required:
  its records are reached through their element only, and the element is
  created hidden while its children are not.
  Columns of the form every type shares keep ADR-205's treatment.
- **CC2 Child fields.** A child may carry the fillable columns of its table's
  form under the ADR-196 rule; its system columns (identity, language,
  visibility, publication, versioning, the `foreign_field`) never. A child
  table with a type field gets the type field's default and that type's form.
- **CC3 FlexForm defaults.** A `flex` column whose data structure resolves
  for the element's `CType` no longer excludes its type; the element is
  created with the data structure's default values (shape
  `data.<sheet>.lDEF.<field>.vDEF`). No FlexForm value is set by a model.
- **CC4 References stay empty.** `folder`, `group` and a `foreign_table`
  `select` no longer exclude a type; the draft leaves them empty. A
  `foreign_table` `select` with a non-empty TCA default still excludes it.
- **CC5 Links.** `link` columns are fillable with `http`, `https`,
  `mailto`, `tel` and `t3://page?uid=<n>` values within the column's
  `allowedTypes`; anything else is refused.
- **CC6 What still excludes.** A required column the draft leaves empty, a
  required child column that is not fillable, a required FlexForm field
  without default, a data structure that does not resolve, and every inline
  column that is not a CC1 relation. ADR-196's deny-lists stay.
- **CC7 One write.** `create_content_element_draft` takes `children`
  (`{<inline column>: [{<child column>: value}]}`) and writes the element and
  its children in one datamap, in the given order, in the run's workspace.
  The DataHandler stores that order in the relation's `foreign_sortby`, else
  the child table's `ctrl.sortby`; a relation with `foreign_default_sortby`
  or a child table without a sort column is read back in its own order, as
  the same records saved from the backend form are.
  The element keeps its `after_content_uid` placement; the children's pid is
  the page. A child carries the element's language. A child without a value
  for a required fillable column is refused before the write.
- **CC8 Grants before the write.** `tables_modify` on each child table, the
  exclude-field grant of each child column set and of the child type field,
  `authMode` on a child type field, and a versioned child table in a draft workspace are checked before
  anything is written; a missing one refuses the call.
- **CC9 Read-back and take-back.** Every child row is read back; a child that
  did not take, or a missing child, deletes the element with its children,
  each child by its own uid.
- **CC10 Approval card.** The card lists every child with its values.
- **CC11 Public service.** `ContentElementComposer` (`@api`) offers
  `describe()`, `typesOfferedOnPage()` and `dataMap()` with the rule above;
  the tools use the same rule.
- **CC12 Description.** The tool description names, per offered type with
  children, the child column and its fields; a type without children adds
  nothing.

## Out of scope

- Setting FlexForm values; `itemsProcFunc` select values (icons).
- Adding or changing children of an existing element (`update_content_element`).
- Translating children (`create_translation_draft`).
- Filtering the tool description by a page's TSconfig (ADR-196 stays).

## Limits

- A child table with a type field is drafted in its default type only.
- The FlexForm defaults are those of the data structure at creation time.

## Verification

| Requirement | Evidence |
| --- | --- |
| CC1-CC6, CC12 | functional `ContentElementComposerTest` (offered and excluded shapes), `CreateContentElementDraftToolTcaTypesTest` (spec) |
| CC7-CC10 | functional `CreateContentElementDraftToolChildrenTest` (live and draft workspace, refusals, take-back, preview) |
| CC11 | `Tests/Unit/Api/api-surface.txt`, functional `ContentElementComposerTest` |
