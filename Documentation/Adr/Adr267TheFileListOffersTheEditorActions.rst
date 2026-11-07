.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-267:

==================================================
ADR-267: The file list offers the editor actions
==================================================

:Status: Accepted
:Date: 2026-11-06
:Amends: :ref:`ADR-158 <adr-158>` (files get a context-menu entry once their identifier is resolved)
:Authors: Netresearch DTT GmbH

.. _adr-267-context:

Context
=======

:ref:`ADR-158 <adr-158>` gave the editor actions one entry point that carries
the record: an item in the context menu of a page or a content element. It left
files out on purpose. In the file list the context menu names a file by its
combined identifier (``1:/user_upload/photo.jpg``), the actions on ``sys_file``
take a uid, and casting the one to the other yields the storage uid, a number
that exists and is wrong. The record said the actions stay in the catalogue
"and are startable once a resolution step exists".

The vision actions (:ref:`ADR-235 <adr-235>`) and the folder batch
(:ref:`ADR-236 <adr-236>`) made files the place where the actions matter most,
and both were reachable only by opening the module and choosing the file or the
folder a second time in the element browser.

Core's file list sends ``sys_file`` as the table for files and folders alike, on
13.4 and on 14.3, and its own item provider resolves the identifier with
:php:`ResourceFactory::retrieveFileOrFolderObject()`.

.. _adr-267-decision:

Decision
========

**The identifier is resolved, never cast.** :php:`EditorActionItemProvider`
takes a non-integer identifier on ``sys_file`` and resolves it with the call
core's provider makes on the same right-click. Any other table with a
non-integer identifier gets no item, as before. The resolution comes after the
grant and the module check, so a user who may run nothing never reaches the
storage.

**A file becomes its uid.** The item carries the ``sys_file`` uid into the
catalogue, which then behaves exactly as for a record. It is offered only when
:php:`FalStorageGate::isFileMetadataEditable()` holds for the user: the storage
allow-list of the FAL tools, the user's file mounts and the ``editMeta``
permission. Every action on ``sys_file`` writes the file's metadata, so a file
the user may only read would lead to a card that can only be refused. That is
the item :ref:`ADR-158 <adr-158>` keeps away by checking the grant.

**A folder becomes a batch scope.** The item opens the catalogue for
``sys_file`` with the folder's combined identifier. The catalogue offers no
single start there; each action links to the batch page of
:ref:`ADR-236 <adr-236>` with the folder as its scope. Nothing new decides what
runs: the batch page lists the folder and asks every file again as the acting
user, as it did when the folder was chosen in the element browser. The item is
offered when :php:`FalStorageGate::isFolderAccessible()` holds, a new check that
asks a storage built for the user, as the file checks do.

**Every refusal looks the same.** A file that does not exist, a storage outside
the allow-list, a folder outside the mounts and an identifier core cannot parse
all mean no item, and an exception never costs the menu its other items.

.. _adr-267-consequences:

Consequences
============

- An editor reaches the vision actions and the folder batch from where the
  files are. The catalogue still says ``sys_file #<uid>`` rather than the file
  name, as for every record.
- The provider resolves a file-list identifier once per right-click, through the
  same core call the file list's own items make for it. An unindexed file is
  indexed by that call, which core's provider does anyway.
- A file on a storage outside the gate's allow-list gets no item, also for an
  administrator. The allow-list is storage 1 unless an integrator changes it
  (:ref:`ADR-235 <adr-235>`).
- Selecting several files in the file list and opening the context menu is not
  a batch: core opens the menu for one item. A selection of single files stays
  the element browser's job on the batch page.

.. _adr-267-alternatives:

Alternatives considered
=======================

**Resolve in the catalogue instead of the provider.** The provider would hand
the combined identifier on, and the catalogue would resolve it. The item would
then appear for files the user cannot use, and the catalogue, which
:ref:`ADR-158 <adr-158>` keeps from reading records, would gain a file read on
every visit.

**A second item provider for the file list.** Two providers would carry two
copies of the grant and module checks, which is the copy that ages.

**A folder item that starts the batch directly.** A batch needs an action, and a
folder has several. Choosing one, the cost estimate and the list of skipped
files are what the batch page is for.
