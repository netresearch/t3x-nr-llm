<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# A content element is drafted with its child records

Governing decisions: ADR-324, ADR-326 and ADR-328. ADR-324 (amends ADR-146, ADR-196, ADR-198 and ADR-205). Tickets NEXT-228
(the assistant cannot draft accordion, card group, icon group, text with
image, image, uploads) and NEXT-229 (nr_landingpage's Page Composer creates
those elements without items).

## Requirements

- **CC1 Children are a column kind.** An `inline` column with a plain
  one-to-many relation (`foreign_table` + `foreign_field`; no MM, no
  `foreign_table_field`, `foreign_match_fields`, `foreign_selector` or
  `foreign_unique` or `symmetric_field`, not `readOnly`, a `minitems` a
  draft can meet, no `behaviour.enableCascadingDelete =
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
  `data.<sheet>.lDEF.<field>.vDEF`). Values a model sets: CC14.
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

- **CC13 Computed items (ADR-326).** A child record's `selectSingle` with an
  `itemsProcFunc` and no `foreign_table` takes one of the items computed for that record, given by
  name or value; an unknown one is refused with the nearest names, a failed
  processor is refused as such. `describe()` lists the names, trying one
  earlier select at a time; choices only a combination brings are not listed.
- **CC14 FlexForm values (ADR-326).** The simple fields of a type's FlexForm
  are set through `fields` as `{column: {field: value}}`, checked, shown on
  the card, written over the defaults and read back. `exclude` fields are for
  administrators; a page's `TCEFORM` rules for the field refuse it.
- **CC15 The choices on request (ADR-328).** The read-only tool
  `list_content_element_choices` lists the names a CC13 column takes, as the
  acting user may use them: per choice of the select they depend on, or for
  the `set` named; filtered by `search`, up to ten searches separated by a
  comma, cut at `limit` with the total; each search without a hit gets its
  nearest names. Its group is `content`. No choices, an unknown name, a
  missing permission and a failed processor are told apart.
- **CC16 A refused plan shows no card (ADR-328).** A draft call its plan
  refuses does not suspend the run; the refusal is its result and nothing is
  written. A call that skipped the approval for it is never executed, also
  where the plan stops refusing before the call is settled.

## Out of scope

- FlexForm sections, references, dates and rich text; `itemsProcessors`; an
  `itemsProcFunc` outside child records.
- Adding or changing children of an existing element (`update_content_element`).
- Translating children (`create_translation_draft`).
- Filtering the tool description, or the choices on request, by a page's
  TSconfig (ADR-196 stays).
- The plan's refusal before the approval for other writers.

## Limits

- A child table with a type field is drafted in its default type only.
- The FlexForm defaults are those of the data structure at creation time.

## Verification

| Requirement | Evidence |
| --- | --- |
| CC1-CC6, CC12 | functional `ContentElementComposerTest` (offered and excluded shapes), `CreateContentElementDraftToolTcaTypesTest` (spec) |
| CC7-CC10 | functional `CreateContentElementDraftToolChildrenTest` (live and draft workspace, refusals, take-back, preview) |
| CC13, CC14 | functional `CreateContentElementDraftToolChildrenTest`, `ContentElementComposerTest` |
| CC15 | functional `ListContentElementChoicesToolTest`, unit `SchemaToolsSpecTest` |
| CC16 | unit `ToolLoopServiceRefusesBeforeApprovalTest`, functional `CreateContentElementDraftToolChildrenTest` |
| CC11 | `Tests/Unit/Api/api-surface.txt`, functional `ContentElementComposerTest` |
