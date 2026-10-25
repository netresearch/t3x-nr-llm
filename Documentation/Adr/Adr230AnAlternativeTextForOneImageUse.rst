.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-230:

======================================================
ADR-230: An alternative text for one use of an image
======================================================

:Status: Accepted
:Date: 2026-10-25
:Amends: :ref:`ADR-198 <adr-198>` (the rights ``replace_file_reference`` asks, for a content element that is an inline child), :ref:`ADR-212 <adr-212>` (the rights ``attach_file_to_record`` asks, for a record that is an inline child)
:Authors: Netresearch DTT GmbH

.. _adr-230-context:

Context
=======

An alternative text belongs to one use of an image: the file reference
(``sys_file_reference.alternative``). The file's own text
(``sys_file_metadata.alternative``) is the default every use without a text
of its own falls back to. ``set_file_alternative_text``
(:ref:`ADR-135 <adr-135>`) writes that default, so it changes the text of
every use of the file that has no text of its own, all at once, and the
writers that touch a reference
(:ref:`ADR-198 <adr-198>`, :ref:`ADR-212 <adr-212>`) set texts only on a
reference they create.

A site-wide check for missing alternative texts in EXT:nr_mcp_agent (its
ADR-026) lists each use of an image whose effective text is empty, and asks
the assistant for a text per hit. That extension decided that the writer
belongs here, next to ``set_file_alternative_text``: every content write the
assistant makes is an nr-llm tool with an approval card, and the tool is
useful without that extension.

.. _adr-230-decision:

Decision
========

``set_file_reference_alternative_text(reference, alternative)`` sets
``alternative`` on one existing ``sys_file_reference``, in place. It keeps
the terms of the other writers (:ref:`ADR-134 <adr-134>`,
:ref:`ADR-135 <adr-135>`, :ref:`ADR-136 <adr-136>`,
:ref:`ADR-198 <adr-198>`, :ref:`ADR-213 <adr-213>`,
:ref:`ADR-214 <adr-214>`):

- **One column of one row**, through the DataHandler as the acting backend
  user, in the live workspace only; read back afterwards, and reported as a
  failure when the value did not land.
- **Any table.** The reference may sit in a ``type => file`` column of any
  table the TCA declares: a content element, a page's own file field, a
  record of another extension. A table on the read-side denylist, and one
  declared ``readOnly`` or (for a non-admin) ``adminOnly``, is refused.
- **The reference's own language.** A translated reference is written as
  it is; the tool takes no language argument and changes no other
  language.
- **The acting user's rights, before the write**: "edit content" on the
  record's page, and for a page's own file field "edit page" as well. The
  form edits a page's file field as a page property, while the DataHandler,
  writing the reference row alone, asks "edit content" on its page (it asks
  "edit page" only when ``pages`` is in the same datamap), so a page's
  reference needs both. Then: the page's edit lock (``pages.editlock``) for
  a non-admin, which the permission bits do not carry and the DataHandler
  refuses; ``tables_modify`` for ``sys_file_reference``; the record-level
  rights of the record (``tables_modify`` on its table, its language,
  ``authMode``, the record's own edit lock); the reference's language; and
  the field-level grants for ``sys_file_reference:alternative`` and for the
  record's file field. A reference or record that is not there, a
  workspace version, a reference outside a file field and a page or record
  the user may not edit share one neutral refusal; a page's file field
  without "edit content", a page edit lock, a missing language right and a
  missing field grant are named.
- **The owner chain.** Where the record is an inline child (a carousel item
  inside a content element), every record that holds it is asked the same
  questions as the record — table, page, record-level rights — up the chain
  until a record no inline column holds, with the same neutral refusal. The
  DataHandler does not ask this (checked in 13.4.37 and 14.3.10: neither
  ``checkRecordEditAccess()`` nor the datamap's update check looks at
  inline parents), but where a child sits in a table of its own (a carousel
  item, a content element of a news record) the backend form reaches it only
  through its owners, so an edit lock or a content type outside the
  editor's ``authMode`` grant on the outer record would otherwise be passed
  by. An owner is any record an ``inline`` column of **another** table
  points to through the row's ``foreign_field``, where its
  ``foreign_table_field`` and ``foreign_match_fields`` agree; without a
  ``foreign_table_field`` every table that holds the uid counts. An inline
  relation of a table to itself is not followed: that is the container
  pattern (EXT:gridelements declares ``tt_content.tx_gridelements_children``
  as an inline relation to ``tt_content``), where the page module edits each
  child on its own and the DataHandler asks about the child only, so the
  container does not stand between the editor and the child. A pointer no
  claiming table holds (the
  owner is gone) is refused as a gone record is, and a cycle ends at a
  record already asked. The same walk guards the two other writers that
  write a file reference on an existing record, ``attach_file_to_record``
  (:ref:`ADR-212 <adr-212>`) and ``replace_file_reference``
  (:ref:`ADR-198 <adr-198>`; a content element is an inline child of another
  table where an extension such as EXT:news holds content elements); all
  three share it in
  :php:`AsksInlineOwnersTrait`.
- **Root level.** A record on the root level (pid 0) is written by an admin
  only. A table that sets ``security.ignoreRootLevelRestriction`` is not
  treated differently; no core table with a file field sets it.
- **No empty text.** An empty string, and a text of whitespace only, is
  refused. This deviates from ``set_file_alternative_text``,
  ``attach_file_to_record`` and ``replace_file_reference``, where ``''``
  is a valid value (an explicit empty override, or a decorative image).
  Whether an image is decorative is a decision of its own, which
  EXT:nr_mcp_agent records in a field of its own, and an empty alternative
  text cannot be told apart from a forgotten one.
- **The card** names the record, its page, the language, the field and the
  file, and shows the reference's own text before and after. Where the
  reference has none (``NULL``), the card says that the file's text applies
  today.
- **Disabled by default**, in the ``editing`` group, an idempotent write,
  usable by non-admins.

The read side exists already: ``read_records`` on ``sys_file_reference``
returns ``alternative`` and keeps ``NULL`` apart from an empty text.
``get_fal_references`` is not extended.

.. _adr-230-consequences:

Consequences
============

- The assistant can describe one use of an image without changing the text
  of every other use of the same file.
- The tool has no editor action, like the writers of ADR-198: it is reached
  through the assistant.
- A use of an image that exists only in a draft workspace cannot be written;
  the check in EXT:nr_mcp_agent overlays workspace rows, this tool does not
  write to them.
- The three reference writers are stricter than the DataHandler for an
  inline child: an editor whom the DataHandler would let write a carousel
  item's reference is refused while the content element holding the item is
  locked or of a type they may not edit. The first version of this tool, and
  the other two until this record, walked no chain and authorised the
  reference against the child record and its page only.

.. _adr-230-revisit:

Revisit when
============

- Workspaces are supported by the writers as a whole: this tool follows
  them then.
- A consumer needs the empty text as a value after all; that is a second
  decision, recorded where the decorative concept lives.
