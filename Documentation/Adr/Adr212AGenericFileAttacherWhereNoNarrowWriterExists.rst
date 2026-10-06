.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-212:

==========================================================================
ADR-212: A generic file attacher, only where no narrow writer exists
==========================================================================

:Status: Accepted
:Date: 2026-10-06
:Authors: Netresearch DTT GmbH

.. _adr-212-context:

Context
=======

A live editorial run on the Netresearch demo (NEXT-199) created a news draft
with ``create_news_draft`` and could not give it its image: "the image was not
attached". :ref:`ADR-197 <adr-197>` lets the assistant create a record in a
table no narrow writer covers, and it cannot set a file field by design (a
relation is not a scalar column). ``attach_file_to_content_element`` references
an existing file from a content element and from nothing else.

The same position as in ADR-197 applies: an extension that wants its records
written brings its own writer, and a writer for the file field of one extension
is reviewed where that extension's TCA is known. Until it exists, every
extension table with a file field has the gap this record closes.

.. _adr-212-decision:

Decision
========

``attach_file_to_record(table, record, file, field?, title?, alternative?,
description?)`` references one existing ``sys_file`` from one file field of one
record. It is the sibling of ``attach_file_to_content_element`` and keeps its
terms (ADR-135, ADR-146, ADR-180):

- **One datamap.** One ``sys_file_reference`` through the DataHandler as the
  acting backend user, in the live workspace, with the new reference's
  placeholder in the parent list, so the record's counter and the reference's
  position come out right in one pass. The read-back asserts row, counter,
  position and every text, and a failed read-back deletes the reference and
  writes the parent list back.
- **No upload, move or rename.** The file must already exist in a storage the
  :php:`FalStorageGate` allows and inside the acting user's file mounts.
- **The record may be hidden.** That is the draft the assistant has just
  created; the tool never changes ``hidden``.
- **The acting user's rights, before the write.** ``tables_modify`` on the
  table, content edit on the record's page, the field-level grant for the file
  field (the DataHandler would drop it in silence), and the neutral refusal
  "Record or file not found, or not permitted." for an absent record, an
  unreachable file and a page the user may not edit alike.
- **Tables by exclusion.** ``pages`` and ``tt_content`` are refused by name and
  point to ``set_page_social_image`` and ``attach_file_to_content_element``;
  ``sys_*`` tables, the read-side denylist and a table declared ``adminOnly``,
  ``hideTable`` or ``readOnly`` are out.
- **A file field of the table, and an extension it accepts.** The field must be
  a ``type => file`` column; ``allowed`` and ``disallowed`` of that column
  (with the three ``common-*`` aliases resolved) are applied to the file.
  Omitting ``field`` is accepted only where the table has exactly one.
- **Default language only**, as the sibling does.
- **Disabled by default**, in the ``editing`` group, a non-idempotent write.

Copyright is not a column of ``sys_file_reference``: it is a property of the
file (``sys_file_metadata.copyright``, EXT:filemetadata) and is written by
``update_fal_asset_meta``. This tool sets what belongs to the reference — title,
alternative text, description — which override the file's for this one place.

Whether an attached image becomes the record's social image is the
extension's rule. EXT:news prints the first reference with ``showinpreview``,
and otherwise the first of ``fal_media``, as ``og:image``.

.. _adr-212-consequences:

Consequences
============

- The assistant can complete a news article from one conversation: draft
  (``create_news_draft``), image (this tool, ``fal_media``), the image's
  metadata (``update_fal_asset_meta``), and read the draft back
  (``read_records`` with ``include_hidden``).
- The tool has no editor action: an editor action is offered on a record of one
  table (ADR-152), and this tool has no such subject. It is reached through the
  assistant only.
- A table whose installation excludes it from ``create_record_draft`` is not
  excluded here; the exclusion list of ADR-197 is a creation rule.

.. _adr-212-revisit:

Revisit when
============

- EXT:news, or a bridge on its behalf, ships its own writer for ``fal_media``:
  this tool then steps back for that table, the way ``create_record_draft``
  does for a table another registered tool creates records in.
- A ``showinpreview`` argument is wanted. Its TCA type depends on the news
  setting ``advancedMediaPreview`` (a checkbox, or a select of three values),
  so the argument and its read-back have to follow whichever is installed.
