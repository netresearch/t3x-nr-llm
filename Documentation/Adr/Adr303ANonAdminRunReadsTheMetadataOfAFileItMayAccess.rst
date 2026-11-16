.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-303:

===================================================================
ADR-303: A non-admin run reads the metadata of a file it may access
===================================================================

:Status: Accepted
:Date: 2026-11-15
:Authors: Netresearch DTT GmbH

Context
=======

``read_fal_asset_meta`` (:ref:`ADR-047 <adr-047>`) returns the title and the
alternative text of a file, but only to administrators: it checks a static
storage list and not the acting user's file mounts, and
:php:`ReadFalAssetMetaTool::requiresAdmin()` says that resolving per-user
permissions there was judged brittle. ``search_fal_files`` answers non-admins
with name, MIME type and size. A run acting for an editor therefore cannot read
the copyright notice or the description of a file the editor sees in the file
list, which is the first question an editorial check of an image asks.

Opening ``read_fal_asset_meta`` to non-admins would also change who reads
metadata in the backend chat, where the tool is offered today. That is a
separate decision and is not taken here.

Since then :php:`FalStorageGate::isFileAccessible()` checks one file against a
storage built for the acting user — the allow-list, the user's storages and
file mounts, and the user's file permissions — and the writers
``set_file_alternative_text`` and ``update_fal_asset_meta`` rely on it for
non-admins. The per-user question is answered; it does not need an
administrator any more.

What the backend shows the same user is the reference. Core's read-only file
view, :php:`ElementInformationController::mainAction()`, opens a file when
:php:`File::checkActionPermission()` allows ``read``, and lists its metadata
through :php:`VisibleSchemaFieldsCollector::getFields()`, which drops a column
whose TCA carries ``exclude`` unless the user holds the ``non_exclude_fields``
grant for it. It asks for no ``tables_select`` grant on ``sys_file_metadata``.
Core marks ``title`` ``exclude``; EXT:filemetadata marks ``copyright``,
``creator``, ``source``, ``publisher`` and ``language`` ``exclude`` (TYPO3
14.3.11 and 13.4.39). ``description``, ``alternative``, ``width`` and
``height`` carry no flag.

Decision
========

A new read tool, ``describe_fal_asset``, in the ``files`` group, enabled by
default and offered to non-admins (spec 058):

1. **The acting user decides.** The file is described when
   :php:`FalStorageGate::isFileAccessible()` allows it for the run's acting
   backend user, never for ``$GLOBALS['BE_USER']``. A file outside the user's
   mounts, a uid no file carries and a run without a user get the same answer,
   byte for byte: ``Asset not found or not permitted.``
2. **The backend's field rule applies.** A column whose TCA carries
   ``exclude`` is reported to a non-admin only with the
   ``non_exclude_fields`` grant ``sys_file_metadata:<column>``. Without it the
   line reads ``withheld (no field permission)`` and the column is not
   selected. No ``tables_select`` grant is required, as in core's file view.
3. **Three answers per field, never blurred.** A stored text is printed as a
   JSON string literal (the file name too, with line breaks and C1 controls
   escaped), an empty field as the bare marker ``not set``, a
   withheld one as the bare marker above. A stored ``not set`` cannot pass for
   the marker and a line break in a description cannot start a line of its
   own. An accessible file without a metadata record reads ``not set``
   everywhere; that file is already known to this user.
4. **Fields.** ``title``, ``alternative``, ``description``, ``width`` and
   ``height`` always; ``copyright``, ``creator``, ``source``, ``publisher`` and
   ``language`` where the live TCA declares the column. A column the TCA does
   not declare is not named in any query, so the tool runs without
   EXT:filemetadata, which nr-llm does not require.
5. **Which row.** The default-language row, as the run's workspace shows it
   (:ref:`ADR-263 <adr-263>`). No language argument: nothing asks for one yet.
6. **No inference.** The tool description says that ``not set`` means unknown
   and that usage rights are never derived from a missing value or from the
   file name. The tool reports; it decides nothing about rights.

``read_fal_asset_meta`` is unchanged and stays admin-only.

Consequences
============

● An editor's run can read the copyright notice, creator and description of
the files in the editor's mounts, and nothing the backend would hide from that
editor.

◐ The answer cannot reveal whether a file outside the user's mounts exists.

◑ ``search_fal_files`` still matches on ``title`` for a user without the
``title`` grant, and ``read_fal_asset_meta`` applies no field grant either.
This record changes neither; a matched title is not printed by
``search_fal_files``, but the match itself says something about it.

◑ The query that selects the metadata row is the one ``read_fal_asset_meta``
runs. The two copies are kept apart because that tool is deliberately left as
it is; a later change to one has to look at the other.

◑ A file whose metadata record is missing reads ``not set`` everywhere, the
same as a record with empty fields. Nothing creates a record.

◑ The tool reads the default language only. A translated title or alternative
text is not reported.

◑ The gate keeps the storages it built for a user, keyed by user and storage,
so a run that checks many files builds each storage once. A queue worker keeps
the gate between runs; without a reset, a mount or permission an administrator
took from a user stayed reachable until the worker restarted. The runtime
therefore resets every ``nr_llm.run_scoped_state`` service, the gate among
them, before a queued run starts and again when it ends (amended 2026-11-16).
