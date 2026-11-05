.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-262:

==============================================================================================
ADR-262: The field writers write into the workspace, and a redirect is refused before the card
==============================================================================================

:Status: Accepted
:Date: 2026-11-05
:Amends: :ref:`ADR-260 <adr-260>` (its consequence that only ``update_content_element`` writes into a workspace), :ref:`ADR-135 <adr-135>` (its refusal of every draft workspace, for the writers named here), :ref:`ADR-084 <adr-084>` and :ref:`ADR-134 <adr-134>` (every declared write suspends: not a call its tool refuses whatever is decided)
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-260 <adr-260>` let ``update_content_element`` write a workspace
version in the draft workspace a run was started in and left every other
writer refusing a draft workspace — after the approval card had been shown
and approved. Five more writers change fields of an existing record:
``update_page_metadata`` (``pages``), ``set_file_reference_alternative_text``
(``sys_file_reference``), ``set_file_alternative_text`` and
``update_fal_asset_meta`` (``sys_file_metadata``), and ``publish_record``
(``hidden`` of ``pages`` or ``tt_content``). Each table declares
``ctrl.versioningWS``, so the DataHandler started with a user in a draft
workspace stores a version (TYPO3 14.3.10 and 13.4.37,
``DataHandler::process_datamap()``, the branch around
``workspaceCannotEditRecord()`` and ``workspaceAllowAutoCreation()``).

``sys_file_metadata`` needed a measurement of its own, because file metadata
is read through FAL rather than as a record. Measured on 2026-11-05 against
TYPO3 14.3.10, as a non-admin editor with a page mount and a file mount who
is a member of the workspace:

- the DataHandler stores a version (``t3ver_wsid``, ``t3ver_oid``) and leaves
  the live row;
- :php:`WorkspaceService::selectVersionsInWorkspace()`, the Workspaces
  module's query, lists that version for the editor, on their page tree and
  over all their mounts — core adds root level ``0`` to the page list for
  tables that ignore the root-level restriction "(e.g. FAL records)";
- in a frontend request previewing the workspace,
  :php:`MetaDataRepository::findByFileUid()` returns the version's values
  (EXT:frontend's ``FileMetadataOverlayAspect`` overlays it); outside the
  frontend it returns the live row.

The redirect writers ``create_redirect``, ``update_redirect`` and
``disable_redirect`` write ``sys_redirect``, which core does not version
(``versioningWS`` false). In a draft workspace the DataHandler either
refuses such a write or — where the workspace allows live editing, or the
table ``versioningWS_alwaysAllowLiveEdit`` — writes the live row at once,
past the workspace (``BackendUserAuthentication::workspaceAllowsLiveEditingInTable()``).
They refused, too, after the card.

``clear_cache`` writes no record. Core's ``DataHandler::clear_cacheCmd()``
does not consult the workspace, and the caches it empties are not kept per
workspace.

Decision
========

**The five field writers write into the run's workspace**, as
``update_content_element`` does under :ref:`ADR-260 <adr-260>`: they refuse a
draft workspace only where the run did not record it, read the record, the
card's current values and the read-back through the workspace overlay
(``fetchRowAsTheUserSees()``, or for the file metadata the live row overlaid
with its version), name the workspace on the card, and end their answer with
"Stored as a draft in the workspace …; live stays unchanged until that
workspace is published", so the model does not tell the editor the change is
live. ``publish_record`` in a draft workspace clears the flag in the
record's version: visitors see the record once the workspace is published.
File metadata is versioned rather than refused: the version is created,
listed in the Workspaces module, and previewed in the frontend, which is
what an editor's own save in the file list produces.

**A call its tool refuses whatever is decided does not wait for approval.**
A tool may implement :php:`RefusesBeforeApprovalInterface`:
``refusalBeforeApproval($arguments, $context)`` answers with the refusal or
null. Wherever the loop asks whether a call needs approval — the approval
scan, the proposal of a guided process, the calls beside it, and the calls
beside a question — it asks :php:`ToolApprovalRule` and then this method; a
refusal means no suspension, and the call goes to the tool. The tool's
``execute()`` asks the same method first and returns its refusal unchanged,
so skipping the approval can never lead to a write. A method that throws
counts as no refusal: the call waits for approval as before.

**The redirect writers refuse in a draft workspace before the card**, with
``refuseUnversionedTableInDraftWorkspace()`` of
:php:`WritesThroughDataHandlerTrait`: in a draft workspace a table whose
``versioningWS`` is not true is refused, whatever the workspace or the table
allow — a change there would go live, and an editor working in a draft
workspace asked for a draft. The answer names the workspace and the table
and says the change can only be made in the live workspace.

**In a turn that suspends for another call the refused call's card says
so.** A turn is approved as one (:ref:`ADR-132 <adr-132>`): when another call
of the turn needs approval, the refused call is pending too, and its card
shows the refusal in the card's language instead of the change. The line
depends on the workspace and the table only, so the card is the same when
the run resumes (:ref:`ADR-184 <adr-184>`). Approving the turn runs the
other call and the refused one, which refuses; declining it gives every
call the denial.

**:php:`ToolApprovalRule` stays context-free.** The registry's boot
validation, the Governance simulator and the unattended filter ask whether a
tool needs approval in general, and for these tools it does.

``clear_cache`` is unchanged.

Consequences
============

✓ An editor in a draft workspace can let the chat change page metadata, an
image's alternative text on one use or on the file, a file's title,
description and copyright, and unhide a page or an element; each change
waits in the Workspaces module, live stays as it was.

✓ A redirect asked for in a draft workspace no longer shows an approval card
that can only end in a refusal.

✓ Another writer that cannot write in the run's context — a table core does
not version — implements the interface with the same helper.

✗ ``set_file_reference_alternative_text`` reads the reference and its record
in the workspace, but the page and the inline owners its checks ask are read
live. A reference whose page or owning record is new in the workspace is
refused as not found.

✗ Outside the frontend core shows a file's live metadata: the file list and
the image's fallback text in a backend form show the live text until the
workspace is published. The metadata version is edited in the file list's
metadata form, which overlays it.

✗ A declined turn gives the refused call the denial, as every other call of
the turn; the model reads "declined" rather than the refusal's reason, and
learns that reason only when it asks again.

✗ The other writers — create, attach, move, copy, delete, localize, replace
a reference, the social image — still refuse a draft workspace after the
card.
