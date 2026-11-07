.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-260:

=========================================================
ADR-260: The assistant writes into the editor's workspace
=========================================================

:Status: Accepted (its consequence that the other read tools read live only is lifted — see :ref:`ADR-263 <adr-263>`; the creating and attaching writers write into the workspace too — see :ref:`ADR-264 <adr-264>`)
:Date: 2026-11-04
:Amends: :ref:`ADR-135 <adr-135>` (its refusal of every draft workspace, for ``update_content_element`` in a run that recorded its workspace), :ref:`ADR-198 <adr-198>` (rail 3, live rows only, for the same tool: relative to the run's workspace); :ref:`ADR-230 <adr-230>`
:Amended: 2026-11-05 by :ref:`ADR-262 <adr-262>` (five more writers write into the workspace) and :ref:`ADR-263 <adr-263>` (the other read tools read the run's workspace); 2026-11-05 by :ref:`ADR-264 <adr-264>` (the creating writers create in the workspace)
:Authors: Netresearch DTT GmbH

Context
=======

An editor who works in a TYPO3 draft workspace expects every change made
there to wait for the workspace's review and publication. The assistant did
not take part in that. Measured on 2026-11-04 against TYPO3 14.3 with
EXT:workspaces loaded:

- ``update_content_element``, run as a user in a draft workspace, refused
  with "this tool only edits the live workspace" — after the approval card
  had been shown and approved. Every writer refuses the same way
  (:ref:`ADR-135 <adr-135>`: "a draft write belongs to the workspace
  publishing machinery … this tool does not silently join them").
- ``get_page_content`` read the live rows, so the model worked from text the
  editor no longer saw in their workspace.
- The acting user of a run is rebuilt from the database
  (:ref:`ADR-083 <adr-083>`), and core sets that user up in the workspace
  ``be_users.workspace_id`` names at that moment. An editor who switched
  workspaces between starting a run and the run being resolved — a queued
  run, a run resumed after an approval — moved the run with them.

The read rule came from commit 61ab69eb3: the read tools had no workspace
restriction at all, so the versions of every workspace reached the
provider, mixed in with live rows. Live-only reading fixed that.

Decision
========

**The actor records its workspace.** :php:`AiActorContext` carries
``workspaceId``: 0 for live, the uid of a draft workspace, or null where it
is not known. It is frozen where the actor is frozen — at the HTTP boundary
from the request's user, in :php:`ToolExecutionContext::fromBackendUser()` —
and travels with a queued or suspended run. A run stored before this ADR has
no workspace: null, never 0.

**The resolver pins it.** :php:`ActingBackendUserResolver` sets the acting
user up in the recorded workspace, where core's own setup reads it, so the
workspace's page tree entry points narrow the user's mounts as in the
backend. It asks :php:`checkWorkspace()` first: a workspace the user may no
longer work in — deleted, access or live access withdrawn — gives no user,
so core never falls back from the pinned workspace to another one and
stores that on the user's record. (Loading the user at all still runs
core's setup on the workspace their record names, which falls back and
stores the fallback when that workspace is gone — core's behaviour, as
before.) Null keeps the previous behaviour.

**A writer that opts in writes into that workspace.** It refuses a draft
workspace only when the run's actor did not record it
(``refuseUnpinnedWorkspace()``); the DataHandler, started with the pinned
user, stores a workspace version, or changes a record new in that
workspace in place. Live is not touched. The writer reads the record as the
workspace shows it: live rows and rows new in that workspace by uid, each
overlaid with its version there; another workspace's draft is not there,
as rail 3 of :ref:`ADR-198 <adr-198>` keeps it in live. Its read-back reads
the version through the live uid. This ADR opts in
``update_content_element``; every other writer keeps refusing a draft
workspace until it is opted in and tested.

**Who may read a draft on a card.** The stored preview lines of a run in a
draft workspace show that workspace's values. A viewer other than the run's
user reads them only when they may work in that workspace, besides the
record-level gate of :ref:`ADR-136 <adr-136>`; the structured preview,
which is read in the viewer's own workspace, is left out where that is not
the run's.

**The approval card names the workspace.** A writer that opted in adds
"Workspace: “<title>” — saved there as a draft, live stays unchanged until
the workspace is published" — the acting user's workspace, the one the run
writes into, never the viewer's. In live the line is absent, so the cards of
approvals pending in live at the upgrade do not change
(:ref:`ADR-184 <adr-184>` compares the lines).

**The provenance mark follows the write.** :php:`AfterAiRecordWrittenEvent`
carries ``workspace``; :php:`MarkAiWrittenRecord` marks the version or the
record new in that workspace, never the live row
(:ref:`ADR-239 <adr-239>`). The mark is published with the version. A
writer that wrote the live row all the same — one outside the DataHandler —
leaves no row in the workspace, and the live row is marked.

**Reading: the acting user's own workspace.** ``get_page_content`` reads the
page as the acting user's workspace shows it: each row overlaid with its
version, the elements new or moved there included, those deleted or moved
away left out, every element under its live uid. The rule of 61ab69eb3
stays for every other workspace: a draft the user is not working in is
never read. The draft the user works in is the text they asked about; the
provider's trust zone and data classes govern it as they govern live text.

Consequences
============

✓ An editor in a draft workspace can let the chat change a content element;
the change waits in the workspace module, live stays as it was.

✓ A run cannot move between workspaces: it writes where it was started, or
not at all.

✗ Only ``update_content_element`` writes into a workspace; the other writers
refuse there, as before. Opting each in needs its own reading of what core
does with that command in a workspace (copy, move, delete, localize and
file relations behave differently there).

✗ The other read tools — ``read_records``, ``search_records``,
``get_pagetree`` — still read live only. (Amended by
:ref:`ADR-263 <adr-263>`: they read the run's workspace too.)

✗ A run queued or suspended before the upgrade has no recorded workspace
and keeps the old behaviour: it writes and reads in live only, and its card
says the refusal where its user now works in a draft workspace.

✗ Whether a viewer may read a card is still asked of the record in the
viewer's own workspace (:ref:`ADR-136 <adr-136>`), besides the membership in
the run's. A viewer working outside the run's workspace may be refused a
card about a record new there, and, where the record was moved in that
workspace, the gate asks about its live page.
