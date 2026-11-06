.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-268:

=========================================================
ADR-268: A file is tagged from a configured category tree
=========================================================

:Status: Accepted
:Date: 2026-11-06
:Authors: Netresearch DTT GmbH

.. _adr-268-context:

Context
=======

Editors tag images with ``sys_category`` records so that lists, filters and
search can find them. :ref:`ADR-235 <adr-235>` lets a model see an image and
fill its texts, but nothing in this extension writes a file's categories, and
the vision fields were texts only.

Categories differ from a text in two ways that decide the design. A category is
a record: a model can choose one, but a model that names a category freely
would either invent titles or need to create records nobody reviewed. And an
installation's categories are one tree for everything — topics, campaigns,
regions, internal markers — of which only a part is meant for tagging images.

:ref:`ADR-194 <adr-194>` already decided how a writer handles a value that must
be one of a set: read the allowed values at call time, refuse anything else,
and name the allowed values in the refusal. It named a ``foreign_table`` select,
whose items are records, as the trigger to revisit that approach.

.. _adr-268-decision:

Decision
========

**A new writer, set_file_categories.** It sets the categories of one file's
default-language metadata record, through the DataHandler, as the acting user,
in the run's workspace — the shape of ``set_file_alternative_text``, ships
disabled, belongs to ``editing``, every call waits for approval
(:ref:`ADR-134 <adr-134>`). It is an editor action on ``sys_file`` with the
vision field ``categories`` (:ref:`ADR-235 <adr-235>`).

**The choice is a list of existing records below a configured root.** An
operator names the root in the extension setting
``vision.categories.rootUid``. The list is the live, visible, default-language
categories below it, not the root itself, read at most ten levels deep and up
to 200 entries. For a user with category mounts (``category_perms``) only the
categories inside them are listed: core's category tree shows that user no
others, and the DataHandler does not check mounts on a write, so the writer
has to. The setting is the extension configuration, beside the other vision
settings, and not TSconfig: a file has no page to read page TSconfig from, as
:ref:`ADR-235 <adr-235>` found for those settings.

**Without a root there is nothing to choose.** The ``categories`` field is then
off, so the editor action is not offered, and the tool refuses every call before
any approval (:php:`RefusesBeforeApprovalInterface`), also when the assistant
calls it directly.

**The model is told the list, and the writer holds it to it.** The vision
prompt names the categories as ``uid: title``. A call naming a uid that is not
on the list is refused before the card, and the refusal names the list, the
ADR-194 rule for records instead of select items.

**The call sets the tree's part of the field.** It names the complete set the
file should carry from the list. Categories the file carries from outside the
list stay, because the call could not have named them: an editor's own tags in
another tree are never removed by a model that was not shown them. An empty set
removes the file's categories from the list only. Like every writer here the
value is absolute, so a repeated call converges.

**The card shows titles and binds uids.** The approval card shows the file's
categories before and after by title, and its technical details carry both uid
lists, because two categories may share a title and ADR-184 compares the lines
on resume.

**The batch counts a relation as empty at zero.** The folder batch of
:ref:`ADR-236 <adr-236>` skips a file that holds a value in a field its action
fills. ``categories`` holds the number of relations, so a numeric column is
empty when it is ``NULL`` or ``0``; comparing it with ``''`` finds no file empty
in SQLite and fails in PostgreSQL.

.. _adr-268-consequences:

Consequences
============

- An editor can have an image tagged from its content, from the catalogue or a
  folder batch, and approves the tags on a card like any text.
- Only an operator's tree can be proposed. A category the tree lacks has to be
  created by a person first.
- The list is in every prompt of the action, so a large tree costs tokens; the
  bound of 200 keeps it finite and cuts the rest without saying so to the model.
- A folder batch leaves out a file that carries any category, also one from
  another tree: the field's count does not tell trees apart.
- Translated metadata records are not tagged, as for every file writer here.

.. _adr-268-alternatives:

Alternatives considered
=======================

**Let the model propose new categories.** A tagging run would then create
records in a shared tree, which an approval card for one file is not the place
to decide.

**Add-only.** The call would only add categories. A wrong tag from an earlier
run could then never be corrected by the action, and the card would hide that
the file keeps tags the model did not choose.

**Replace the whole field.** Simpler, and it would remove tags of other trees
that the model was never shown.

**The root in page or user TSconfig.** Page TSconfig needs a page, which a file
does not have. User TSconfig would make the vocabulary differ per editor for
the same file and the same batch; the category mounts already narrow it per
user where an installation wants that.

.. _adr-268-revisit:

Revisit when
============

- An installation needs several roots, for example one per storage or folder.
- A tree outgrows the bound and the cut starts to matter.
