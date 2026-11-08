.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

=====================================
Write-tool authorization and previews
=====================================

.. _administration-tools-writing:

The writing tools
=================

Twenty-three tools change anything at all: ``update_page_metadata``,
``set_page_social_image``, ``set_file_alternative_text``,
``update_fal_asset_meta``, ``set_file_categories``, ``move_content_element``,
``create_content_element_draft``, ``create_page_draft``,
``create_translation_draft``, ``attach_file_to_content_element``,
``attach_file_to_record``, ``create_record_draft``, and the six that act on existing pages and content
elements — ``update_content_element``, ``publish_record``,
``delete_record``, ``copy_record``, ``move_page`` and
``replace_file_reference``, and ``set_file_reference_alternative_text``, which
sets the alternative text of one existing file reference on any record, and
the four administrator-only site tools ``create_redirect``,
``update_redirect``, ``disable_redirect`` and ``clear_cache``
(:ref:`ADR-257 <adr-257>`). The nineteen content writers write through the
TYPO3 DataHandler,
as the acting backend user, in the live workspace and in the draft workspace
a run was started in (each family below), on **one** record per
call — plus, for a delete, a copy or a page move, what core carries along
with that record, which the approval card counts (:ref:`ADR-135 <adr-135>`,
:ref:`ADR-146 <adr-146>`, :ref:`ADR-180 <adr-180>`,
:ref:`ADR-197 <adr-197>`, :ref:`ADR-198 <adr-198>`,
:ref:`ADR-199 <adr-199>`, :ref:`ADR-230 <adr-230>`).

Seven writers that change fields of an existing record also work in a draft
workspace: ``update_content_element``, ``update_page_metadata``,
``set_file_reference_alternative_text``, ``set_file_alternative_text``,
``update_fal_asset_meta``, ``set_file_categories`` and ``publish_record``. In a run started in that
workspace they store a workspace version, and live stays as it is until the
workspace is published — a record ``publish_record`` unhides there becomes
visible to visitors only then. Their approval card names the workspace, and
their answer to the model says the change is a draft. A file's metadata
version is listed in the Workspaces module and shown in the workspace's
frontend preview like any other; outside the frontend, core shows a file's
live metadata. The read tools read as that workspace shows it (see :ref:`tool-workspaces`). A
run writes only in the workspace it was started in
(:ref:`ADR-260 <adr-260>`, :ref:`ADR-262 <adr-262>`).

Five writers that create a record also work in a draft workspace:
``create_content_element_draft``, ``create_page_draft``,
``create_record_draft``, ``attach_file_to_content_element`` and
``attach_file_to_record``. In a run started in that workspace the record they
create — a hidden element, a hidden page, a hidden record, a file reference —
is new in that workspace and waits in the Workspaces module; it goes live only
when the workspace is published. The page, the parent page, the element or
page to place it after, and the record a file is attached to may themselves
exist in that workspace only; another workspace's records are not there. A
file reference goes onto the record's version in the workspace, which the tool
makes first. The approval card names the workspace, and the answer to the
model says the record is a draft. ``create_record_draft`` and
``attach_file_to_record`` are refused before the approval card for a table
TYPO3 does not version: a record there cannot be kept as a draft. Where the
page has no room in its sort order at the place asked for, a create in a draft
workspace is refused: TYPO3 would make room by renumbering the page's live
records and other workspaces' drafts, and put this workspace's drafts out of
order. Another place on the page, or room made in live, gets past it
(:ref:`ADR-264 <adr-264>`).

``set_page_social_image`` and ``replace_file_reference``, which replace a
reference, also work in a draft workspace. In a run started there the page or
element gets its version first, the new reference is new in the workspace,
and the replaced one is marked for deletion there; live keeps its image until
the workspace is published. A translation that follows the page's image, and
the translated references of a removed one, follow in the workspace as core
synchronises them. A reference that exists only as a draft there is
discarded when it is replaced or removed. The card names the workspace, and
the answer to the model says the change is a draft
(:ref:`ADR-274 <adr-274>`).

Five writers that change the structure also work in a draft workspace:
``move_content_element``, ``move_page``, ``copy_record``, ``delete_record``
and ``create_translation_draft``. In a run started in that workspace they run
core's command there: a move is a move version of the record and of its
translations, a copy and a translation are records new in the workspace, and
a delete marks the record and its translations for deletion there — the
workspace's publication deletes them live, with everything core deletes with
them. Pages, elements and anchors are taken as that workspace shows them, a
page or element new or moved there included. The card names the workspace;
the delete card says the record is marked there instead of offering the
recycler. Refused before the card, because core's command does something
else in a workspace: deleting a record new in the workspace (core discards it
for good) or moved there (core discards the move instead), or one whose
translation is; copying a page that holds records of a table core does not
version, such as a TypoScript template; ``overwrite`` of an existing
translation; and, on TYPO3 13.4, a translation where the one in that language
is marked for deletion in the workspace. A call refused before the card stays
refused when the turn is approved, even if an earlier call of the turn removed
the reason. The five writers run in the run's workspace — TYPO3's workspace
aspect and the workspace of the global backend user are set to it for the
call — so core copies and translates the same whether the
approval runs in the backend or in a worker
(:ref:`ADR-266 <adr-266>`).

A redirect cannot be kept as a draft: TYPO3 does not version
``sys_redirect``. In a draft workspace ``create_redirect``,
``update_redirect`` and ``disable_redirect`` are therefore refused **before**
the approval card — the run does not pause for them, the tool answers that the
change can only be made in the live workspace, and nothing is written. In a
turn that pauses for another call, their card says the same
(:ref:`ADR-262 <adr-262>`).

What holds for all of them:

- They ship **disabled**, sit in their own ``editing`` group, and are not
  offered until both the group and the tool are enabled.
- **Every call pauses for a human decision** (:ref:`ADR-134 <adr-134>`). The
  approval card names the record and the values, together with the values the
  write would REPLACE (:ref:`ADR-136 <adr-136>`); nothing is written until
  somebody presses Approve, and the approver must themselves be permitted to
  run the tool (:ref:`ADR-133 <adr-133>`).
- Permissions are enforced twice: the tool checks the acting user's own rights
  first, and the DataHandler enforces its own rules on top. A non-admin
  therefore writes only what the backend already lets them write.
- A field the user lacks the "exclude field" grant for is dropped by the
  DataHandler **without an error**. Every one of them re-reads the record
  afterwards and reports that as a failure rather than as a successful write.
- The other way round, an error TYPO3 logs during the write does not mean the
  write failed: a hook of the installation can write another record along
  with this one, and core logs its refusal of that record — "Attempt to
  modify record pages:170 without permission or non-existing page" — beside
  a write that landed, as the backend form shows "saved" and that message
  together. The read-back decides: what took is reported as written (in
  part, where some fields did not take or where what core carries along is
  not read back) with "TYPO3 also reported: …"; a write whose read-back
  fails is answered as failed — or, for ``create_redirect``, a redirect
  created with other values as written in part — naming what the read-back
  found and then TYPO3's messages. One exception: ``move_content_element`` answers a
  reorder on the element's own page and column beside a complaint as
  refused, because page and column cannot show whether it happened
  (:ref:`ADR-198 <adr-198-amendment-read-back-first>`).
- A call the tool would refuse is refused **whole** rather than applied in
  part, and a record the acting user may not reach is refused with the same
  words as a record that does not exist — so a refusal never confirms that a
  uid exists.
- They act on **live rows only**. A workspace draft of a page, a content
  element or a file reference is not there for them: it is not written,
  copied, counted or shown on an approval card, even when its uid is named
  (:ref:`ADR-198 <adr-198>`). The exceptions are the writers above that work
  in a draft workspace, in a run started there: they address the live rows
  and the rows new in THAT workspace, each as that workspace shows it, and
  never another workspace's draft (:ref:`ADR-260 <adr-260>`,
  :ref:`ADR-262 <adr-262>`, :ref:`ADR-264 <adr-264>`, :ref:`ADR-266 <adr-266>`).
- ``update_page_metadata`` and ``update_content_element`` bind a field's
  before and after to the whole value: two short values in full, otherwise
  the section that differs, where it starts, and the length and a short hash
  of both values — so a change past the visible part, such as an appended
  link, still shows. A character a reader cannot see, such as a zero-width
  space or a direction mark, is written as its code point.
- ``update_page_metadata``, ``update_content_element`` and
  ``set_page_social_image`` also give the card each field as structured
  values: its label, the value stored now and the value proposed, read with
  the rights of the user the card is shown to (:ref:`ADR-214 <adr-214>`,
  item 9). A field carries its length only against a recommended range, and
  the range comes from the extension configuration
  ``tools.structuredPreview.ranges``, entries ``table.field:min-max``, shipped
  as ``pages.description:140-160``. A field without an entry carries no
  length. An empty value removes every range.

See :ref:`tool-workspaces` for the current shared workspace/language matrix.
Use the focused catalogues for each tool's fields and limits.


.. toctree::
   :maxdepth: 1

   MetadataTools
   CreatingTools
   StructuralTools
