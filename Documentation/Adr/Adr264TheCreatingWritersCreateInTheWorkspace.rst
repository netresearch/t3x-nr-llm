.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-264:

======================================================================
ADR-264: The creating writers create their record new in the workspace
======================================================================

:Status: Accepted (amended; current contract below)
:Date: 2026-11-05
:Amends: :ref:`ADR-260 <adr-260>` and :ref:`ADR-262 <adr-262>` (their consequence that the creating writers refuse a draft workspace after the card), :ref:`ADR-146 <adr-146>`, :ref:`ADR-180 <adr-180>` and :ref:`ADR-197 <adr-197>` (their live-workspace restriction)
:Amended: 2026-11-05 by :ref:`ADR-266 <adr-266>` (move, copy, delete and translate act in the workspace); 2026-11-08 by :ref:`ADR-274 <adr-274>` (the two replacing writers replace in the workspace, and ``attach_file_to_record`` asks the inline owners in the workspace)
:Authors: Netresearch DTT GmbH

.. _adr-264-current-contract:

.. note::

    Current contract (reviewed 2026-11-07): Structural writers now support the recorded draft workspace within the
    explicit refusal limits; see :ref:`tool-workspaces`.

    The decision text below records its original context; the linked successor
    explains the changed contract.


Context
=======

:ref:`ADR-260 <adr-260>` and :ref:`ADR-262 <adr-262>` let the writers that
change fields of an existing record store a workspace version in the draft
workspace a run was started in. The writers that CREATE a record still
refused a draft workspace, after the approval card:
``create_content_element_draft`` (``tt_content``), ``create_page_draft``
(``pages``), ``create_record_draft`` (any table without a writer of its own),
``attach_file_to_content_element`` and ``attach_file_to_record`` (a
``sys_file_reference`` and the parent's field). ``set_page_social_image`` and
``replace_file_reference`` create a reference too, and delete the one it
replaces.

Measured on 2026-11-05 against TYPO3 14.3.10 with a backend user in a draft
workspace, the DataHandler started with that user:

- a new record is one row new in the workspace: ``t3ver_wsid`` the workspace,
  ``t3ver_state`` 1, ``t3ver_oid`` 0, the pid of the page or of the record
  it is placed after (``DataHandler::process_datamap()``, the
  ``$createNewVersion`` branch; ``resolveSortingAndPidForNewRecord()`` and
  ``getSortNumber()`` place it after a live element or one new in the
  workspace, and after a moved element where the workspace shows it);
- the permission to create on a page is asked of the STORED row the pid
  names, ``BackendUtility::getRecord('pages', $pid)`` — the live row of a page
  with a version, the row itself of a page new in the workspace — not of the
  overlay (13.4.37 and 14.3.10);
- a ``delete`` of a row new in the workspace discards it: the row is removed
  (``DataHandler::deleteAction()`` → ``discard()``); a ``delete`` of a live
  reference stores a delete placeholder (``t3ver_state`` 2);
- a new file reference of a parent with a version, or new in the workspace,
  is related to the parent's LIVE uid, and the parent's version counts it;
- versioning a parent versions its inline children, and
  ``RelationHandler::writeForeignField()`` writes ``tstamp`` and
  ``sorting_foreign`` 1..n onto the LIVE child rows by uid (14.3.10 and
  13.4.37, the update at the end of the method). The live order stays, and
  an editor's own save in the workspace does the same;
- a datamap that names the parent's existing references by their live uids
  writes the new order onto those live rows and leaves the versions' order:
  a new reference then does not come last in the workspace. Named by the
  versions' uids, it comes last and live keeps its rows;
- discarding a parent's version discards its children's versions and the
  references new under it;
- where the sort order of a page has no room for the new record — it goes
  first and the first record sorts below 1, or the record after its anchor
  sorts at most one above it, rows new in any workspace counted —
  ``DataHandler::increaseSortingOfFollowingRecords()``
  renumbers ``sorting`` of every row on the page with ``t3ver_oid`` 0,
  live rows and rows new in any workspace alike (14.3.10 and 13.4.37,
  called from ``getSortNumber()``). An editor's own create in the workspace
  does the same. Versions are left where they were, and the workspace
  overlay takes the version's ``sorting``: measured on 14.3.10 with the
  anchor at 256, the next element at 257 and that element's version at
  257, the new element got 512, the live next element 769 — and in the
  workspace the new element came after the next element instead of after
  its anchor.

Decision
========

**The five creating writers create in the run's workspace.** Like the field
writers they refuse a draft workspace only where the run did not record it,
name the workspace on the card and end their answer with "Stored as a draft
in the workspace …". Everything around the write looks at the records as
the workspace shows them, through
:php:`CreatesRecordsInTheRunsWorkspaceTrait`:

- the page, the parent page, the anchor element or page, the content element
  or record a file is attached to: the live row overlaid with its version, or
  a row new in that workspace; never another workspace's row, and not one
  deleted in the workspace;
- the page permission is asked of the stored row, as core asks it; for a
  record a file is attached to that the workspace moved to another page,
  of both pages — core asks the update of the record on the page its stored
  row is on, and the new reference's insert on the page it moved to;
- the read-back reads the row new in the workspace;
- the duplicate warnings of the two draft tools look at live rows and the
  rows of that workspace, each as the workspace shows it: deleted, moved
  away or renamed there is no copy. The connected-translation check of
  ``create_content_element_draft`` counts live rows and the rows of that
  workspace — not another workspace's drafts;
- a rollback deletes the new row, which core discards.

**A file reference goes onto the parent's version, made first.** The attach
writers make the parent's version with its own ``version`` command before the
datamap, then name the field with the uids of the references' rows in the
workspace. A write that fails — refused, not read back, or thrown — discards
the version it made, and with it the reference; where the parent already had
a version, or is new in the workspace, the reference is deleted and the
field list put back. The workspace is as it was, and where that does not
happen the answer says so. Whether the version is this call's is read from
the DataHandler's own copy map: a version somebody else made in the meantime
is never discarded.

**The attach writers ask what core asks before it updates the parent.**
Making a version asks only the table grant and "show page"
(``versionizeRecord()``); the update of the parent then asks
``checkRecordEditAccess()`` of its stored row — language, ``authMode`` values
such as the content type, edit lock — and the discard of a version asks the
same. A user who lacks one of them would get a card, a reference row and a
version nobody can take back, and the refusal only after the approval. So the
plan asks :php:`ActsOnAnExistingRecordTrait`'s ``mayEditRecord()`` of the
stored row before the card, in live as in a draft workspace.

**A create that finds no room in the sort order is refused, and nothing
moves.** The creating writers run their datamap through
:php:`ToolDataHandler` with ``keepSortOrdersInADraftWorkspace()``, which


overrides ``increaseSortingOfFollowingRecords()``: in a draft workspace the
run leaves the sort order alone and records that it found no room. The
record it inserted is deleted again — a row new in a workspace is removed
outright — and the call is refused with the reason. Shifting this
workspace's versions as well would need a write outside the DataHandler,
and the live rows and the other workspaces' drafts would still move. Live
runs renumber as before. Moves reach the same renumbering and are not part
of this record. Unlike the other refusals of a draft workspace, this one
comes after the approval card: whether there is room is known only inside
the DataHandler run, from rows of every workspace, which the card does not
read.

**A table TYPO3 does not version is refused before the card.** The five
writers implement :php:`RefusesBeforeApprovalInterface` with
``refuseUnversionedTableInDraftWorkspace()`` (:ref:`ADR-262 <adr-262>`):
``create_record_draft`` and ``attach_file_to_record`` for the table the call
names, the attach writers for ``sys_file_reference`` as well, the two draft
tools for ``tt_content`` and ``pages`` — which a stock TYPO3 versions; the
refusal matters where an installation takes that off, or sets
``versioningWS_alwaysAllowLiveEdit``, which 13.4 honours for a versioned
table too. A name the TCA does not declare is left to the plan, which
refuses it in its own words. The card shows the refusal instead of the
change.

**The two replacing writers refuse a draft workspace before the card**
(lifted by :ref:`ADR-274 <adr-274>`).
``set_page_social_image`` and ``replace_file_reference`` delete a reference
and handle the copies of its translations; in a workspace each delete has to
name the right one of the live uid, the version's uid and the uid of a row
new there, or it discards a draft instead of deleting a record. That is not
built here. They implement :php:`RefusesBeforeApprovalInterface` with the
live-workspace refusal they gave after the card.

Consequences
============

✓ An editor in a draft workspace can let the chat draft a page with content,
a record and an image on them; all of it waits in the Workspaces module, and
live stays as it was.

✓ In live too, an attach the user's record-level rights do not allow is
refused before the card instead of after the approval.

✓ The model can build on its own drafts in that workspace: a page it created
there takes an element, an element it created there takes an image and
anchors the next element.

✗ Live is not untouched to the byte. Versioning a parent's references writes
``tstamp`` and renumbers ``sorting_foreign`` on the live reference rows
(core, see above). Which references, which files, their content and their
order stay as they were; nothing of another workspace is read or shown.

✗ Where a page's sort order has no room at the place asked for — the record
goes first and the first record sorts below 1, or the record after the
anchor sorts at most one above it — a create in a draft workspace is
refused after the approval, where an editor's own create in the workspace
succeeds and puts the drafts out of order. The assistant can reach this by
itself: each create halves the gap of 256, so the tenth create in a row at
the top of a page, or the ninth after the same element, is refused. The
rows counted are those of the whole page, across columns and languages, and
include other workspaces' new rows, so an editor may see room on the page
and still get the refusal. Another place on the page, or room
made in live, gets past it.

✗ ``set_page_social_image`` and ``replace_file_reference`` still write in live
only; in a draft workspace the editor has to switch to live for them (lifted by
:ref:`ADR-274 <adr-274>`).

✗ ``attach_file_to_record`` asks the records that hold the record as an
inline child (:ref:`ADR-230 <adr-230>`) live: where such an owner exists in
the workspace only, the call is refused as not found (lifted by
:ref:`ADR-274 <adr-274>`: the owners are read in the workspace).

✗ ``sys_file_reference`` is not marked as written by an AI
(:php:`MarkAiWrittenRecord` marks ``pages`` and ``tt_content``), in a
workspace as in live.

✗ The connected-translation check of ``create_content_element_draft`` counts
the rows stored in live and in the workspace without the overlay: a
translation deleted there, or a version of one, still counts. It can refuse
an element the page would take; it cannot let through one it would not.

✗ The move, copy, delete and translation writers still refuse a draft
workspace after the card (lifted by :ref:`ADR-266 <adr-266>`).
