.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-264:

=========================================================================
ADR-264: The creating writers create their record new in the workspace
=========================================================================

:Status: Accepted
:Date: 2026-11-05
:Amends: :ref:`ADR-260 <adr-260>` and :ref:`ADR-262 <adr-262>` (their consequence that the creating writers refuse a draft workspace after the card), :ref:`ADR-146 <adr-146>`, :ref:`ADR-180 <adr-180>` and :ref:`ADR-197 <adr-197>` (their live-workspace restriction)
:Authors: Netresearch DTT GmbH

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
  references new under it.

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
workspace. A write that fails its read-back discards the version it made, and
with it the reference: the workspace is as it was.

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

**The two replacing writers refuse a draft workspace before the card.**
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

✓ The model can build on its own drafts in that workspace: a page it created
there takes an element, an element it created there takes an image and
anchors the next element.

✗ Versioning a parent's references writes ``tstamp`` and renumbers
``sorting_foreign`` on the live reference rows (core, see above). Which file
and in which order stay as they were.

✗ ``set_page_social_image`` and ``replace_file_reference`` still write in live
only; in a draft workspace the editor has to switch to live for them.

✗ ``attach_file_to_record`` asks the records that hold the record as an
inline child (:ref:`ADR-230 <adr-230>`) live: where such an owner exists in
the workspace only, the call is refused as not found.

✗ ``sys_file_reference`` is not marked as written by an AI
(:php:`MarkAiWrittenRecord` marks ``pages`` and ``tt_content``), in a
workspace as in live.

✗ The connected-translation check of ``create_content_element_draft`` counts
the rows stored in live and in the workspace without the overlay: a
translation deleted there, or a version of one, still counts. It can refuse
an element the page would take; it cannot let through one it would not.

✗ The move, copy, delete and translation writers still refuse a draft
workspace after the card.
