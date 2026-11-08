.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-272:

======================================================================
ADR-272: The replacing writers replace a reference in the workspace
======================================================================

:Status: Accepted
:Date: 2026-11-08
:Amends: :ref:`ADR-264 <adr-264>` (its refusal of a draft workspace before the card for ``set_page_social_image`` and ``replace_file_reference``, and the consequence that both write in live only)
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-264 <adr-264>` let the writers that add a file reference write it
in the draft workspace a run was started in, and kept the two writers that
REPLACE one refusing a draft workspace before the approval card:
``set_page_social_image`` sets ``pages.og_image`` or ``pages.twitter_image``,
deleting the image there with ``replace``; ``replace_file_reference`` puts a
new reference in the place of one on a content element, or removes it. Each
deletes a reference, and in a workspace the delete has to name the right uid,
or it discards a draft instead of deleting a record. An editor in the
workspace "Entwurf" met that refusal on 2026-11-06.

Neither tool writes a file. Both read ``sys_file`` and write
``sys_file_reference`` and the parent's field, and core versions both tables
and ``pages`` and ``tt_content``. So nothing of these two calls is beyond
TYPO3's workspaces.

What core does with the delete, read in ``DataHandler::deleteAction()`` of
TYPO3 14.3.10 and 13.4.37 (the dispatch is the same in both):

- a uid of a row whose ``t3ver_wsid`` is not 0 — a version, or a row new in
  the workspace — is discarded (``discard()``): the row is removed;
- a live uid whose record has a version in the user's workspace in the
  default state turns that version into a delete placeholder
  (``t3ver_state`` 2) and deletes the record's translations the same way
  (``deleteL10nOverlayRecords()``);
- a live uid without a version gets a new delete placeholder
  (``versionizeRecord()`` with ``$delete``), its translations too.

Measured on 2026-11-08 against TYPO3 14.3.10 (SQLite), with the tools as
below, as an administrator — with the global backend user in live as well —
and, for the social image, as an editor who holds PAGE_EDIT and not
CONTENT_EDIT on the page:

- making the parent's version first versions its references; the field named
  by the versions' uids and a delete of the replaced reference by its LIVE uid
  leave the workspace showing exactly the new image at the old one's place,
  the replaced one marked for deletion there, and live unchanged but for the
  ``tstamp`` and ``sorting_foreign`` core writes onto the live references
  when it versions their parent (:ref:`ADR-264 <adr-264>`);
- a delete by the uid of the reference's version discards that version: the
  old image shows again in the workspace beside the new one;
- a translated page that follows its parent's image gets a copy of the new
  image and its copy of the old one marked for deletion, in its own version;
  the translated overlay of a removed content-element reference is marked for
  deletion with it, and the translated element's version counts what it
  still shows;
- a reference new in the workspace, one the assistant set there earlier, is
  discarded by its own uid: it never was live.

Decision
========

**Both tools write in the run's draft workspace**, as the attaching writers
do (:ref:`ADR-264 <adr-264>`): they refuse a draft workspace only where the
run did not record it (:ref:`ADR-260 <adr-260>`), or where an installation
took the versioning off a table they write — ``pages`` or ``tt_content``, and
``sys_file_reference`` (``refuseUnversionedTableInDraftWorkspace()``,
:ref:`ADR-262 <adr-262>`) — before the card. ``execute()``, the card and the
refusal run in the acting user's workspace (:php:`AsksItsRefusalFirstTrait`,
:ref:`ADR-266 <adr-266>`).

**The parent's version first.** The page or the element gets its version
with its own ``version`` command before the write
(``versionForTheWrite()``). The datamap then names the parent's field with
the rows that hold its references in the workspace — the versions — and the
new reference is new in the workspace.

**A delete names the uid a reference is addressed under**: its live uid, so
core marks it for deletion, or the uid of the row new in the workspace, so
core discards a draft that never was live. Never the uid of a version.

**Everything around the write reads the workspace**: the page as the
workspace shows it with PAGE_EDIT asked of the stored row
(``pageForWriteIn()``), the element and its page, the reference, the
references of the field and their translations, the page's translations, the
read-back of the new row and of the parent version's counter. Another
workspace's rows are not there, and a version addressed by its own uid stays
refused as not found.

**A write that fails is taken back**: the version this call made is
discarded, and with it everything the call wrote below it; where the parent
had a version already, or is new in the workspace, the new reference is
discarded by its uid and the field named by the rows it held before.

**The card and the answer name the workspace.** Both cards carry the line of
:ref:`ADR-260 <adr-260>`. Where ``set_page_social_image`` replaces an image
in a draft workspace, its warning says the image is removed from the page in
the workspace, live keeps it until the workspace is published, and an image
that exists there only as a draft is discarded — instead of the recycler,
which does not hold workspace rows. The answer ends with the draft note of
:ref:`ADR-262 <adr-262>`.

**Removing a draft reference is not refused.** :ref:`ADR-266 <adr-266>`
refuses ``delete_record`` of a record new in the workspace, because core
discards it beyond the recycler. A reference new in the workspace is the
image the workspace itself set — typically by an earlier call of these tools
or of the attaching writers — and replacing it again is the editor's
ordinary next step; the card says it is discarded.

Consequences
============

✓ An editor in a draft workspace can let the chat set or replace a page's
social image and replace or remove an image on a content element; each change
waits in the Workspaces module, and live stays as it was.

✓ Every writer that writes pages and content works in the run's draft
workspace; only the redirect writers refuse it (``sys_redirect`` is not
versioned).

✗ Live is not untouched to the byte: versioning the parent writes ``tstamp``
and renumbers ``sorting_foreign`` on its live references, as an editor's own
save in the workspace does (:ref:`ADR-264 <adr-264>`).

✗ A reference new in the workspace that a call replaces or removes is
discarded, not kept in a recycler; the card says so for the social image,
and the content-element card names the reference that goes.

✗ ``replace_file_reference`` asks the records that hold an element as an
inline child (:ref:`ADR-230 <adr-230>`) live, as ``attach_file_to_record``
does: an owner that exists in the workspace only refuses the call as not
found.

✗ The translations were measured on TYPO3 14.3.10. On 13.4 the dispatch of
the delete reads the same; the functional tests of both majors in CI are the
measurement there.
