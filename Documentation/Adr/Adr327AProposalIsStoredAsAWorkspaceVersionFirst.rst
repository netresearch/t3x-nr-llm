.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-327:

=====================================================================
ADR-327: A proposal is stored as a workspace version before approval
=====================================================================

:Status: Proposed
:Date: 2026-11-24
:Authors: Netresearch DTT GmbH

.. _adr-327-context:

Context
=======

The approval card states a proposed change as text. The change is not stored,
so no page can show it. The product owner chose on 2026-11-24 that a proposal is
stored as a workspace version first and that the approval publishes it. This
record examines that choice against the runtime and against TYPO3 13.4.35 and
14.3.11. What it says about core was **read at those tags and not measured**,
unless it names a record that measured it. Everything it recommends is open.

**An approval runs the tool today.** :php:`ToolLoopService` throws
``ToolApprovalRequiredException`` before the tool is invoked
(:ref:`ADR-134 <adr-134>`, :ref:`ADR-141 <adr-141>`).
:php:`ToolLoopService::resume()` runs ``previewCall()`` again, compares the
lines (:ref:`ADR-184 <adr-184>`) and then executes each pending call. After a
write that was already staged, the second preview would show the proposed value
as the current one in the workspace, and a creating tool would create a second
record. So an approval of a staged change must publish recorded rows and must
not run the tool.

**A workspace holds one version per record.** On a live record
:php:`DataHandler::process_datamap()` takes the existing version
(``BackendUtility::getWorkspaceVersionOfRecord()``) and changes it in place. The
version then holds an editor's draft and the proposal together.
:php:`DataHandlerHook::version_swap()` publishes the whole row, so publishing the
proposal would publish the draft, and discarding the proposal would discard the
draft.

**Publishing overwrites the live row.** ``version_swap()`` writes the version's
row onto the live uid, apart from a few kept fields such as the sort order. A
live edit made after the version came into being is overwritten. Publishing
several rows is several updates without a common transaction.

**Publishing has its own rights.** ``version_swap()`` and
``publishNewRecord()`` ask :php:`WorkspacePublishGate::isGranted()` of the
DataHandler's user: an administrator, the workspace's owner, or a member who may
work in live, unless the workspace limits publishing to owners. A workspace can
also limit publishing to the publish stage. The demo's workspace "Entwurf" has no
owner and no publish limit, and its seed grants the workspace editors' group 995
no ``workspace_perms``. Whether a group above it grants live on the running demo
was not read.

**Anyone who may publish can publish a proposal.** The Workspaces module and
``workspace:autopublish`` build a swap for every version of the workspace
(``WorkspaceService::getCmdArrayForPublishWS()``). A ``processCmdmap_beforeStart``
hook sees the command map before any command runs, at both tags, and can take a
swap out. A path that writes the rows without the DataHandler is not covered.

**Not everything is a version.** The tables of the writers divide so:

.. list-table::
   :header-rows: 1

   * - Writers
     - Can be staged
     - Reason
   * - ``update_content_element``, ``update_page_metadata``, ``publish_record``,
       ``set_file_reference_alternative_text``
     - yes
     - one version of one record (:ref:`ADR-260 <adr-260>`,
       :ref:`ADR-262 <adr-262>`)
   * - ``set_file_alternative_text``, ``update_fal_asset_meta``
     - yes, with a limit
     - ``sys_file_metadata`` declares ``versioningWS`` at both tags; outside the
       frontend core shows the live values (:ref:`ADR-262 <adr-262>`)
   * - ``create_content_element_draft``, ``create_page_draft``,
       ``create_record_draft``, ``copy_record``
     - later
     - rows new in the workspace (:ref:`ADR-264 <adr-264>`,
       :ref:`ADR-266 <adr-266>`), created hidden; a hidden element has no preview
       link in the chat
   * - ``create_translation_draft``, ``move_content_element``, ``move_page``,
       ``delete_record``, ``attach_file_to_content_element``,
       ``attach_file_to_record``, ``replace_file_reference``,
       ``set_page_social_image``, ``set_file_categories``
     - later, one by one
     - relations, placeholders and translations; versioning a parent writes
       ``tstamp`` and ``sorting_foreign`` onto live child rows
       (:ref:`ADR-264 <adr-264>`), so "live is unchanged" is not true for them
   * - ``create_redirect``, ``update_redirect``, ``disable_redirect``,
       ``clear_cache``, remote tools and delegations
     - no
     - ``sys_redirect`` is not versioned; the others act outside the records

The files themselves are not versioned either: an uploaded or changed file is
live at once.

**Three records state what this contradicts.** :ref:`ADR-134 <adr-134>` and
:ref:`ADR-136 <adr-136>`: a declared write suspends before it runs.
:ref:`ADR-133 <adr-133>` and :ref:`ADR-172 <adr-172>`: the approver gate and the
second approver stand between a proposal and a write. :ref:`ADR-198 <adr-198>`
keeps publishing out of its six writers. The security consequence is that a
model steered by injected text writes rows before a person has read anything.
DataHandler hooks run, versions and history entries appear, the workspace's
members see them, and the gates guard publication only.

.. _adr-327-decision:

Recommendation
==============

**A staged proposal is a class of its own, with a ledger.** A table records per
pending call the run, the call id, the workspace, and for each row the table,
the live uid, the version's uid, a digest of the version's row and a digest of
the live row it was made from (the digests of :ref:`ADR-298 <adr-298>`). The
ledger entry is written before the staged write and completed after it. It does
not live in ``suspended_state``, which a failed decode after the claim clears.

**When.** The loop stages an opted-in call in the approval scan, at the point
where it makes the preview, as the run's acting user, through a method of its
own beside ``execute()``. A tool opts in through an additional interface, one
tool at a time, as :ref:`ADR-260 <adr-260>` did. The lines of the card are made
before the staged write.

**Where, for a run started in live.** Into one workspace an administrator names
for proposals. A proposal is staged only when the acting user may work in it and
:php:`WorkspacePublishGate` would let that user publish, the tables are
versioned, and the workspace holds no version of any row the call touches. In
every other case, and where EXT:workspaces is not installed (it is a development
dependency only), the call keeps today's path: card first, write after. The card
says which of the two it is. Using the user's own draft workspace was rejected:
there may be none or several, and it holds the user's drafts.

**A run started in a draft workspace** writes there today, and publishing is a
separate act (:ref:`ADR-260 <adr-260>`). A second workspace cannot be laid over
the first. Staging means the version is made in that workspace before the card,
where the record has none yet. The approval keeps the version there, and
publishing stays a separate act (the product owner, 2026-11-24).

**Approval** claims the run as today and asks the gates of :ref:`ADR-133
<adr-133>` and :ref:`ADR-172 <adr-172>`. It compares both digests of every row,
for the whole turn, before anything is published. Equal: it publishes exactly
the ledger's rows with one command map as the run's owner and reads the live rows
back. The command map after ``processCmdmap_beforeStart`` must hold no row that
is not in the ledger, because core adds dependent records
(:php:`CommandMap::process()`); otherwise nothing is published and the card says
so. A changed live row or a changed version publishes nothing: the version is
discarded, the change is staged anew from the live row and the run suspends
again, as under :ref:`ADR-184 <adr-184>`. Never is more published than core lets
the owner publish, and the workspace's stages are not skipped: a workspace that
publishes from the publish stage only is refused as a proposal workspace.

**Nobody else publishes a proposal.** A ``processCmdmap_beforeStart`` hook
takes the swap of a ledger row out of every command map except the approval's
own, the Workspaces module and the scheduler included, and logs it. The approved
publish is marked by an object the runtime holds, not by a value a request sends.

**Every exit discards.**

.. list-table::
   :header-rows: 1

   * - Exit from the wait
     - What happens to the version
   * - Denied
     - discarded in the denying segment, read back; the denial stands even when
       that fails
   * - Cancelled (inbox, command, ended process, four-eyes stop)
     - discarded by the cancelling request, else by the reaper
   * - Failed state, revoked pin, vanished user or configuration, crash between
       staging and suspension or between publishing and settling
     - the reaper compares ledger and run: no waiting run, no version; a row
       already published is closed, never published again
   * - Nobody decides
     - stays, as a waiting run does not expire by age; an administrator may set
       an age after which the run is cancelled

Discarding removes only a row whose digest is the one staged. A version an
editor changed in the meantime is kept and reported.

.. _adr-327-slices:

Slices
======

1. The ledger, the interface, the publish and discard paths, the hook and the
   reaper for ``update_content_element`` and ``update_page_metadata``, runs
   started in live, and a preview link on nr_mcp_agent's card. Estimated at two
   to three weeks, not measured; the runtime's exits are the larger part.
2. The other field writers.
3. Creating writers, with the hidden-element question settled.
4. Relations, moves, deletes and translations, each with its own measurement.

Slice 1 does not preview a new element.

**Measurements slice 1 owes**, on both tags and as a non-administrator: publish
of one row while another version waits; the dependent rows core adds; the swap
over a live edit; the hook against the module, autopublish and an administrator;
a discard that fails halfway; preview of a hidden element in a workspace; what
hooks such as automatic translation do to a staged row.

.. _adr-327-open:

Open questions
==============

The product owner settled two on 2026-11-24: an approval in a run started in a
draft workspace keeps the version there, and a proposal of a run started in live
is staged in a workspace an administrator names for proposals. Whether and when
this is built is not decided by this record. Open:

- Does a new element go live visible on approval, or stay hidden as today?

.. _adr-327-consequences:

Consequences
============

● An approver sees the proposal on the page before deciding.

● An approval publishes the rows that were shown, and a changed base is found by
a digest of the row.

✕✕ A write happens before a person has seen it. Live content does not change,
but rows, hooks and history do.

✕✕ Two paths exist side by side for good, because not every write is a version.

✕ An editor without the right to publish keeps today's path, and so does a
second proposal for a record that already has one.

✕ Publishing several rows is not atomic. A failure halfway is reported with the
rows that are live.

.. _adr-327-alternatives:

Alternatives considered
=======================

**Links only.** The card links to the page as it is. Nothing is written before
the approval, and nothing of the above is needed.

**Render the proposal without storing it.** A frontend overlay of the proposed
values for one request. It keeps the invariants, covers field changes only, and
was not built.

**Let the approver publish in the Workspaces module.** It gives up the binding
between the card and what becomes live, and the second approver.
