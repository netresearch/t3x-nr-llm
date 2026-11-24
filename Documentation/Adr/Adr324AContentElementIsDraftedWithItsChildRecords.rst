.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-324:

============================================================================
ADR-324: A content element is drafted with its child records
============================================================================

:Status: Accepted (its "no FlexForm values" and "no ``itemsProcFunc`` values"
    for child records are settled - see :ref:`ADR-326 <adr-326>`)
:Date: 2026-11-23
:Amends: :ref:`ADR-196 <adr-196>` (its column rule: inline child records,
    FlexForm columns, folder, group and record-backed select columns, and
    links); :ref:`ADR-146 <adr-146>` (its one-record rule, for the children
    of the element it creates); :ref:`ADR-198 <adr-198>` (the types and
    columns ``update_content_element`` takes follow the same rule);
    :ref:`ADR-205 <adr-205>` (accordion, tab and carousel no longer stay
    excluded; its treatment of the shared form stands)
:Amended: 2026-11-24 by :ref:`ADR-326 <adr-326>` (simple FlexForm fields, and
    selects of child records with computed items)
:Authors: Netresearch DTT GmbH

.. _adr-324-context:

Context
=======

:ref:`ADR-196 <adr-196>` offers a content type unless its form holds an
excluding column, and it counts a FlexForm, inline child records, a group or
folder reference and a record-backed ``select`` as excluding. On the
Netresearch demo (TYPO3 14.3, bootstrap_package 16.0.1) that rule leaves out
the elements editors use most for landing pages: ``accordion``,
``card_group``, ``icon_group``, ``tab``, ``timeline`` and the
carousels carry an inline column of items and a FlexForm; ``textpic``,
``image`` and ``media`` carry bootstrap_package's ``file_folder``
(``folder``); core's ``uploads`` carries ``file_collections``
(``group``). The assistant cannot create them (NEXT-228).

nr_landingpage's Page Composer has the same gap from the other side: it
writes ``CType``, header, subheader, body text and one image per section,
so an accordion it creates has no items (NEXT-229). It reads the TCA with a
reader of its own.

ADR-196 kept these out for two reasons. A FlexForm is configuration whose
data structure depends on a pointer the tool would have to resolve. Children
are "a batch by another name": the one-record rule of
:ref:`ADR-146 <adr-146>` is what keeps every preview readable and every
refusal whole.

The frontend-editing markers of the Visual Editor (FriendsOfTYPO3
``visual_editor``) were considered as a source of the shape and are not
one: they are emitted while a page renders, in edit mode, only where a
template opted in, and they carry record coordinates and a few validation
keys copied from TCA, no child records. The Visual Editor derives them from
``TcaSchemaFactory``. TCA is the source both read.

.. _adr-324-decision:

Decision
========

**One element with its children is one draft.** The table of the element
stays ``tt_content``, one element per call, hidden, through the DataHandler
as the acting user, with the approval card, the grant checks and the
read-back of ADR-196. The element may carry child records of the inline
columns its form shows, created in the same datamap. The approval card lists
every child with its values, and the read-back checks every child row; a
child that did not take takes the whole element back, children included.
That keeps ADR-146's reason: the approver reads the whole of what comes into
being, and a refusal leaves nothing behind.

**The column rule gains two kinds and moves four column types.**

``children``
   An ``inline`` column whose relation is a plain one-to-many:
   ``foreign_table`` and ``foreign_field``, no MM table, no
   ``foreign_table_field`` or ``foreign_match_fields``, a child table
   that has TCA and a form and is not ``tt_content``, ``pages``,
   ``sys_file_reference`` or another ``sys_`` or ``be_`` table. One
   level deep. The child table is bounded as :ref:`ADR-197 <adr-197>` bounds
   a generic creator - no system or sensitive table, nothing ``adminOnly``
   or ``readOnly`` - except that ``hideTable`` is required: item tables
   are hidden from the list module because they are edited and rendered
   through their parent, which is how a draft writes them, and a draft
   element is created hidden while its children are not. The child's form
   is read with
   the same rule; what a child may carry is its fillable columns, never its
   system columns (read from the child table's own ``ctrl``), and its own
   relations, FlexForms and inline columns stay empty. A child table with a
   type field gets the type's default value and that type's form; the type
   field is not offered; the tool writes the type value into the datamap, so
   the DataHandler's record access check asks ``authMode`` for it. A child
   carries the element's language: TYPO3 before 14.3.10 does not align it
   with the parent's, so the tool sets it on every supported version. The
   DataHandler sets the child's ``foreign_field`` when it resolves the
   parent's inline list; the tool never sets it. A child without a value for
   a required fillable column is refused before the write.

``defaults``
   A ``flex`` column whose data structure resolves for the element's
   ``CType``. The draft writes the data structure's default values, as the
   backend form does for a new record; a model sets no FlexForm value.

``unfilled``
   Gains ``folder``, ``group`` and a ``select`` backed by a
   ``foreign_table``: a reference the draft leaves empty, like a file.

``fillable``
   Gains ``link`` (until now unfilled), validated against the schemes
   ``http``, ``https``, ``mailto``, ``tel`` and a page link
   ``t3://page?uid=<n>``, intersected with the column's
   ``allowedTypes``, and read back. A card's call to action is a link, and
   the approver sees the target on the card.

**The shared form keeps ADR-205's treatment.** A column of the form every
type shares (bootstrap_package's ``background_image_options`` FlexForm in
``frames``) is neither judged by the new kinds nor written: a data
structure there that does not resolve takes no type away.

**A requirement the draft cannot meet still excludes.** A required column
the draft leaves empty (``required`` or ``minitems`` of one or more), a
required child column that is not fillable, a FlexForm field that is
required and has no default, and a data structure that does not resolve
exclude the type. Every inline column that is not ``children`` excludes
it. Every other ADR-196 exclusion stays as it was: the deny-list, plugins
and their item groups (so ``form_formframework`` stays out), and the
column types it names - ``slug``, ``password``, ``uuid``, ``json``,
``passthrough``, ``user``, ``none``, ``imageManipulation`` - and
every unknown TCA type.

**The grants are the ones the DataHandler would ask.** Before the write the
tool asks ``tables_modify`` on each child table, the exclude-field grant of
each child column it sets, and ``authMode`` on a child table's type field,
which the DataHandler's record access check asks of a new row as well; in a
draft workspace a child table must be versioned. A missing grant refuses the
call before anything is written.

**The rule and the writing are one public service.** nr_landingpage needs
the same shape for its sections, so the rule is exposed, not copied:
``ContentElementComposer`` (``@api``) describes an offered content
type as an array a model can read (its fillable fields and its children's
fields), narrows a list of types to those a page's
``TCEFORM.tt_content.CType`` ``keepItems``/``removeItems`` and the
user's ``authMode`` allow, and builds the datamap of one element with its
children after checking values and grants. The tools and the Page Composer
apply the same rule through it. Its result is an array shape, so a later
widening (FlexForm values a model may set) adds keys and removes none.

.. _adr-324-not:

What this does not do
=====================

- **No FlexForm values.** A model cannot choose an accordion's open item or
  a card group's column count; the data structure's defaults apply. Making
  the scalar FlexForm fields fillable is the next step if editors ask for
  it.
- **No ``itemsProcFunc`` values.** A ``select`` whose items come from a
  processor (bootstrap_package's icon sets and icons) has no static items
  and stays unfilled; an icon group's items are drafted without icons.
- **No children of existing elements.** ``update_content_element`` keeps
  updating scalar columns; it neither adds nor changes child records.
- **The tool description stays page-independent**, as ADR-196 decided. A
  type a page's TSconfig takes out is answered with the types the page
  offers (#1333). The Page Composer knows its page before it asks the
  model and narrows the template's types there.

.. _adr-324-consequences:

Consequences
============

● On the demo's TCA (bootstrap_package 16.0.1, read from its sources, not
measured on the instance), ``accordion``, ``card_group``,
``icon_group``, ``tab``, ``timeline``, the carousels, ``textpic``,
``image``, ``media``, ``gallery`` and ``uploads`` become reachable,
with no configuration and no list in this extension. A new type of the same
shape needs nothing.

✕ ``image``, ``gallery``, ``media`` and ``uploads`` are drafted without
their files; ``attach_file_to_content_element`` or an editor adds them.

● The assistant and the Page Composer read one rule. The Page Composer's own
TCA reader is no longer the source for the types nr_llm offers.

✕ A draft can now create several rows. The approval card grows with the
children, and a refusal names the child it is about.

✕ An element with an empty FlexForm column would render with the template's
fallbacks, so the defaults are written; a data structure that changes later
does not change existing drafts.

✕ An icon group or timeline is drafted without icons; an editor picks them.

✕ A child's own FlexForm (the carousel item's background options) stays
empty: the draft writes defaults one level deep, for the element only, and
the child's template falls back to its defaults.

✕ ``header_link``, in the header palette every content type shares,
becomes a column both ``create_content_element_draft`` and
``update_content_element`` may set, like every fillable column of the
shared form (:ref:`ADR-205 <adr-205>`).

✕ The carousel item's type field declares ``authMode``
``explicitAllow``; an editor group without that grant cannot draft
carousel items.

.. _adr-324-revisit:

Revisit when
============

Editors ask for FlexForm options (column count, open item) or icons in
drafts. The first is a flag on the reader and the existing validators; the
second needs the processed items of a ``select``, which only the backend
form computes today.
