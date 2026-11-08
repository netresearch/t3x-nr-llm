.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-274:

======================================================================
ADR-274: The replacing writers replace a reference in the workspace
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
refused as not found. Before ``replace_file_reference`` updates an element,
core asks the element's STORED row — without an edit lock set on its draft
alone, and on the page it sits on live — and the tool asks the same row, as
the attaching writers do
(:php:`AttachesToAStoredRecordTrait`); the page the element shows on in the
workspace, which takes the new reference, is asked as well. The translated
elements whose overlays core deletes along are asked by their stored rows
too. In the same way
``set_page_social_image`` asks PAGE_EDIT of each translation's stored row,
which is what core asks when it saves the translations with the page.

**A write that fails is taken back**: the version this call made is
discarded, and with it everything the call wrote below it. This holds for
every failure: a read-back that does not match, an old reference the delete
did not remove (``replace_file_reference`` as ``set_page_social_image``), and
an exception thrown half-way, which leaves the tool after the take-back —
also one thrown by a hook while core makes the version, before the call
learns its uid. Where the parent had a version already, or is new in the workspace, a
read-back that does not match puts the parent back instead — the new
reference discarded by its uid and the field named by the rows it held
before — and an old reference the delete did not remove is handled as in
live: ``set_page_social_image`` puts the page back, while
``replace_file_reference`` answers that the element carries both and one
has to be removed by hand. An exception there takes
back the rows the write added by the same reading of before, described next,
and keeps the draft that was there.

Where the call made the version, the take-back works from a reading of the
workspace taken just before that version was made
(:php:`CreatesRecordsInTheRunsWorkspaceTrait::discardVersion()`): the rows
there of the record and of its translations — versions, delete placeholders,
translations new there — and the rows there of their inline children, file
references among them, and of the children's own inline children, four levels
down: an image of a content element that a news record holds is a draft two
levels below the record. The same code serves ``attach_file_to_content_element``
and ``attach_file_to_record``. Each step was measured on TYPO3 14.3.10 and
13.4.37:

- Every row there afterwards that was not there before is this call's, and is
  discarded: the children first, then the record's version and the drafts
  core made of its translations in the same run — a translated element's
  version and the delete placeholder of its overlay reference
  (``deleteL10nOverlayRecords()``), a translated page's version and its copies
  of the images. Core's discard of the record's version does not reach the
  translations' drafts: it looks for translations by the version's uid
  (``discardLocalizationOverlayRecords()``), and a translation's version points
  at the live record.
- Every row that was there before is kept. Core's discard of the record's
  version takes the versions of its inline children along
  (``discardRecordRelations()``), whoever made them; an alternative text an
  editor saved on one reference of the element is a draft of that reference
  without a draft of the element, and the discard of the version the call
  made for its write deleted it. The take-back's DataHandler is told the rows
  of before, by their own uid and by the live uid they are the version of,
  and skips them (:php:`ToolDataHandler::keepInTheWorkspace()`).
- The discard runs with the parent's table in its datamap, which is never
  processed. Core discards a file reference only with the right to update
  one (``DataHandler::hasPermissionToUpdate()``): CONTENT_EDIT, unless a
  ``pages`` row is in the run, when it is PAGE_EDIT — the right the write was
  made under. Without it, for an editor who may edit a page's properties and
  not its content, core discarded the page version and left the new
  reference and the version of the page's live image in the workspace.
- What is left is read from the rows and compared with the reading of before,
  column by column for the state, the pointers, the file and the texts of
  each row. Only where they match does the answer say the workspace was left
  as it was. Otherwise it names each row that differs — made by the call and
  still there, there before and gone, there before and changed, and how — and
  sends the editor to the Workspaces module.

A row of before that the failed write CHANGED stays changed. The case met is
a translated page whose translation already had a draft of its image copy,
made by an editor's save of the translation's title: core's synchronisation
turns that draft into a delete placeholder, and the row keeps its uid.
Putting it back would take discarding the editor's draft, and the take-back
never discards what was there before; the answer names the row and says
that publishing the workspace would delete the copy live.

**The card and the answer name the workspace.** Both cards carry the line of
:ref:`ADR-260 <adr-260>`. Where ``set_page_social_image`` replaces an image
in a draft workspace, its warning says the image is removed from the page in
the workspace, live keeps it until the workspace is published, and an image
that exists there only as a draft is discarded — instead of the recycler,
which does not hold workspace rows. Where ``replace_file_reference`` replaces
or removes a reference that exists only as a draft there, its card says the
reference is discarded with its texts and crop and the recycler does not
keep it. The answer ends with the draft note of :ref:`ADR-262 <adr-262>`.

**An element moved in the workspace is refused.** Core's ``move`` command
gives the element a move version there (``t3ver_state`` 4) and moves each of
its references along with their own move versions. A delete of such a
reference by its live uid makes core discard the reference's move
(``DataHandler::deleteAction()``, 14.3.10 and 13.4.37: a version that is not
in the default state is discarded), and core has no command that deletes a
moved reference and keeps the move. Measured with a real ``move``: a
replacement left the new reference beside the old one and the old
reference's move discarded, and a removal left the element counting one
reference while two showed. ``replace_file_reference`` therefore refuses an
element that has a move version in the workspace — and a translated element
moved on its own, whose overlay of the reference core deletes along — before
the approval is asked (``refusalBeforeApproval()``, as :ref:`ADR-266 <adr-266>`
refuses what core does differently in a workspace), for any user, and says to
publish or discard the move first. A page moved in the
workspace is not affected: its references keep the page as their ``pid``, so
only the page gets a move version, and ``set_page_social_image`` writes onto
it as onto any draft (measured).

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
save in the workspace does (:ref:`ADR-264 <adr-264>`). On TYPO3 13.4, where
the page's translation follows its image, core's synchronisation of the
translation also moves the translation's LIVE copy of the image from the
default-language page's pid to the translation's own uid when it versions the
translation; the tools name no pid of that row. 14.3 leaves it (measured in
the functional tests of both majors). Which file the copy references, its
translation pointer and its order stay. Taking a failed write back does not move it back.

✗ A reference new in the workspace that a call replaces or removes is
discarded, not kept in a recycler, with its texts and crop; both cards say
so. The removal's write target names that reference, and since its row is
gone, the observed outcome of the run (:ref:`ADR-185 <adr-185>`) reads as
discarded although nobody reversed it.

✗ ``replace_file_reference`` cannot change the images of an element moved
in the workspace until the move is published or discarded.

✗ ``replace_file_reference`` asks the records that hold an element as an
inline child (:ref:`ADR-230 <adr-230>`) live, as ``attach_file_to_record``
does: an owner that exists in the workspace only refuses the call as not
found.

✗ A failed write does not always leave the workspace as it was. A draft that
was there before and that the write changed is named in the answer and left
for the editor, never discarded (see above). The reading of before covers
the record, its translations and four levels of their inline children. Where
the write throws, the take-back runs and the exception leaves the tool; what
the take-back could not put back is not in the answer then, since there is
none. A row another session makes in the same workspace around the same
record while the call runs is not in the reading of before, so a take-back
in that window counts it as the call's and discards it; TYPO3 itself does not
lock a record against two editors either.

✗ The translations were measured on TYPO3 14.3.10 locally and on the 13.4
line in the functional tests CI runs; the dispatch of the delete reads the
same in both.
