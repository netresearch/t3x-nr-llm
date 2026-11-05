.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-266:

==============================================================================
ADR-266: Move, copy, delete and translate act in the workspace the run is in
==============================================================================

:Status: Accepted
:Date: 2026-11-05
:Amends: :ref:`ADR-262 <adr-262>` (its consequence that move, copy, delete and localize refuse a draft workspace after the card), :ref:`ADR-198 <adr-198>` (live rows only, for the four writers named here in a run's recorded draft workspace), :ref:`ADR-146 <adr-146>` (``move_content_element`` and ``create_translation_draft`` in the live workspace only)
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-260 <adr-260>` and :ref:`ADR-262 <adr-262>` let the writers that
change fields write a workspace version in the draft workspace a run was
started in. The writers that change the structure — ``move_content_element``,
``move_page``, ``copy_record``, ``delete_record`` and
``create_translation_draft`` — still refused a draft workspace, after the
card.

Core's commands do different things in a workspace. Measured on
2026-11-05 with ``ToolDataHandler`` and an administrator put into a draft
workspace, on TYPO3 14.3.10 and 13.4.37 (SQLite) and 14.3.10 (MariaDB),
every row of ``pages`` and ``tt_content`` dumped after each command:

- **move** (``DataHandler::moveRecord()``): a version of the record with
  ``t3ver_state`` 4 carrying the new ``pid``, ``sorting`` and, from the paste
  ``update``, the new ``colPos``; its translations get a move version too.
  "After element X" resolves to X's place in the workspace, a target page
  new in the workspace is accepted, and a record new in the workspace is
  moved in place. Live is unchanged.
- **copy** (``copyRecord()``, ``copyPages()``): every copied row is new in
  the workspace (``t3ver_state`` 1), copied from the workspace's version of
  the source; a page's content is copied as the workspace shows it, without
  the translations of its elements (see Consequences).
- **delete** (``deleteAction()``): a live record becomes a delete version
  (``t3ver_state`` 2), and so do its translations; a record changed in the
  workspace has its version turned into one. A page is marked with its
  translation only: its subpages and content get no version, and publishing
  the workspace deletes the live branch. Two cases are not a deletion: a
  record **new** in the workspace is discarded — removed from the database,
  beyond the recycler — and a record **moved** there has its move version
  discarded: it is back at its live place and nothing is deleted.
- **localize** (``localize()``): the translation is new in the workspace,
  made from the workspace's version of the source and placed where the
  workspace shows it. Deleting an existing translation and localizing again
  — this tool's ``overwrite`` — differs between the cores: 14.3.10 marks the
  old translation and creates the new one; 13.4.37 marks the old one and
  then refuses the localization ("There already are localizations").

Core resolves the version of a moved or deleted translation against the
parent's version: its ``l18n_parent`` names the parent's version uid.

Decision
========

**The five writers act in the run's draft workspace**, as the field writers
do under :ref:`ADR-260 <adr-260>`: they refuse a draft workspace only where
the run did not record it, and their DataHandler, started with the pinned
user, produces what core produces there. Live is not touched.

**They read the structure as that workspace shows it.** The record, the
source and target pages and an anchor are read with
``fetchRowAsTheUserSees()``; lists by parent — siblings, subpages, the
content on a page, a record's translations — with the predicate of
:ref:`ADR-263 <adr-263>`, each row under its live uid
(``ReadsTheRunsWorkspaceTrait::rowsShownIn()``,
``translationsInWorkspace()``, which also matches a translation pointing at
the parent's version). A page or element moved or new in the workspace is
where the workspace puts it; another workspace's draft is not there.

**The read-back reads the workspace**: the move version's page and column,
the copy and its translations new in the workspace and hidden, the
translation new there, and for a delete the record and its translations
gone from the workspace's view. A delete in a draft workspace does not ask
for the subpages and the content of a page, which core deletes live only
when the workspace is published.

**The card and the answer name the workspace.** Move, copy and translation
cards carry the line of :ref:`ADR-260 <adr-260>`; a delete card replaces
"Recoverable: yes, from the recycler" with "Workspace: … — marked for
deletion there; live stays unchanged until the workspace is published …
Until then the mark can be discarded in the Workspaces module". The answer
to the model ends with the draft note of :ref:`ADR-262 <adr-262>`; a delete
says "Marked … for deletion in the workspace …".

**Refused before the card** (:php:`RefusesBeforeApprovalInterface`), in a
draft workspace, whatever the approver decides:

- a run that did not record its workspace;
- a table the call writes that core keeps no draft of
  (``refuseUnversionedTableInDraftWorkspace()``, :ref:`ADR-262 <adr-262>`);
  for a page copy, every table that holds records on the page — core copies
  them along, and a record of a table without versioning, such as
  ``sys_template``, would be written live where the workspace allows live
  editing, and refused after the approval otherwise;
- ``delete_record`` of a record new in the workspace, or moved there, and of
  a record whose translation is new or moved there — core's command would
  discard rather than delete (it deletes each translation the same way,
  ``deleteL10nOverlayRecords()``); and of a page whose live branch holds a
  record — a subpage, a content element — the workspace moved away:
  publishing the mark runs core's live delete on the live branch, which
  deletes that record from where it still is and discards its move;
- ``create_translation_draft`` with ``overwrite`` where a translation
  exists: refused on both cores, so the tool behaves the same on both and
  never leaves a marked translation without a new one;
- on TYPO3 13.4, ``create_translation_draft`` where the translation in that
  language is marked for deletion in the workspace: 13.4 still counts it and
  refuses the localization (measured on 13.4.37); 14.3 makes the new one.

The refusal is asked after the plan's own checks, so a uid the user may not
touch keeps the neutral refusal, and :php:`PlansOneEditorialWriteTrait::refusalInItsWorkspace()`
answers it for all five. ``execute()`` and the card ask it first.

**Another workspace's drafts still stop a delete.** In a draft workspace the
delete is only a mark; publishing it runs core's live delete, which discards
the drafts of every other workspace on what it deletes. The guard of
:ref:`ADR-198 <adr-198>` keeps counting those, a translation this workspace
already marked included; the run's own workspace's drafts — the record's own
change there — are its own work and are not counted.

**Provenance.** Only ``create_translation_draft`` reports a ``CREATED``
record; :php:`MarkAiWrittenRecord` marks the translation new in the
workspace (:ref:`ADR-260 <adr-260>`). Move, copy and delete report
``TEXT_UNCHANGED`` or ``DELETED`` and are not marked, in live as before.

Consequences
============

✓ An editor in a draft workspace can let the chat move, copy, delete and
translate pages and content; each change waits in the Workspaces module,
live stays as it was.

✓ A delete or an overwrite core would turn into something else in a
workspace is refused before the card, with the reason and what to do
instead.

✗ A record new in the workspace, or moved there, or one whose translation
is, cannot be deleted through the chat in that workspace; it is discarded in
the Workspaces module.

✗ A page that holds a TypoScript template, or any record of a table without
versioning, cannot be copied through the chat in a draft workspace.

✗ The drafts of other workspaces are counted when the delete is marked. A
draft another workspace makes afterwards is not: publishing the mark
discards it, as publishing the same mark made by hand in the page tree
would.

✗ Inline children are not asked about: a table core does not version that an
extension hangs below a content element as inline children would be copied
or localized live in a workspace that allows live editing. TYPO3's own
``tt_content`` has none; its file references are versioned.

✗ ``overwrite`` of a translation is not available in a draft workspace,
although TYPO3 14.3 could do it.

✗ The limit :ref:`ADR-260 <adr-260>` names for the card's read gate reaches
further here: the gate runs the plan in the viewer's own workspace. A member
of the run's workspace who views the card from another workspace gets it
withheld where the plan depends on the run's drafts — a record, page or
anchor new or moved there, and a delete whose record has a draft there
(counted as another workspace's from where the viewer sits). The gate fails
closed; the run's own user always sees the card.

✗ The approval card of a page delete counts the subpages and records the
workspace shows; publishing deletes the live branch, which a draft of the
same workspace may have changed.

✗ Core finds no site for a page new in the workspace: its site lookup reads
the live rootline. ``copyL10nOverlayRecords()`` then copies no translation of
an element (measured on 14.3.10 and 13.4.37): a page copied in a draft
workspace brings its own translations along, but not those of its elements,
and an element copied onto a page new in the workspace comes without its
translations. The page card counts what core copies; the element card's
translation line states core's rule as in live, and the answer counts the
translations actually copied. For the same reason
``create_translation_draft`` refuses a record on a page new in the
workspace: the page belongs to no site.
