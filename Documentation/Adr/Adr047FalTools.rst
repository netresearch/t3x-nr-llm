.. include:: /Includes.rst.txt

.. _adr-047:

==================
ADR-047: FAL tools
==================

:Status: Accepted
:Date: 2026-07-09
:Deciders: nr_llm maintainers

Context
=======

The tool family covered records, schema, configuration and code — but not
the File Abstraction Layer. The recurring questions "which storages exist?",
"what is in this folder?", "find that PDF", "where is this image used?" and
"why is this image broken?" had a single narrow answer
(``read_fal_asset_meta``, one file by uid) and no navigation around it.

FAL output egresses to the external LLM provider, so the family needs one
shared containment: storage scoping. A single allow-list in one place beats
five copies of the same intersection logic.

Decision
========

Five read-only built-in tools in the NEW group ``files`` — the first group
added since the initial taxonomy (ADR-043); third-party extensions remain
advised to use their extension key as group:

``list_fal_storages``
   The effective storages with uid, name, driver and status flags. The
   server-side base path is never part of the output.

``browse_fal_folder``
   One folder's subfolders (with file count) and files (size, MIME type),
   resolved exclusively through the storage API. Identifiers are
   storage-relative; entries are capped.

``search_fal_files``
   Substring search over file name and default-language metadata
   title/alternative. The model-chosen query is LIKE-escaped (literal
   ``%``/``_``), missing files are excluded, results are capped.

``get_fal_references``
   ``sys_file_reference`` usages of one file as ``table:uid (field)`` —
   hidden references marked, deleted ones invisible. The output states
   explicitly that soft references (RTE links, plain URLs) are not tracked,
   so "no references" is never read as "safe to delete".

``find_missing_files``
   ``sys_file`` rows with ``missing = 1`` in the effective storages, with
   the total count always reported alongside the capped listing.

Access model
============

All five share :php:`FalStorageGate`: the configured storage allow-list
(default ``[1]``), intersected for non-admins with the storages reachable
through their file mounts
(:php:`BackendUserAuthentication::getFileStorages()`, verified identical on
13.4 and 14). Fail-closed: no backend user or an empty intersection yields
the neutral denial. A file or folder outside the gate is indistinguishable
from a missing one — the same neutrality contract as
``read_fal_asset_meta``. ``get_fal_references`` additionally drops rows
whose referencing table fails :php:`TableReadAccessService` for non-admins.
Server paths never egress: listings use storage-relative identifiers, and
``list_fal_storages`` omits the base path by design.

Consequences
============

- The FAL gap in the tool taxonomy is closed with navigation
  (storages → folders → files), search, usage and integrity tools.
- The new ``files`` group can be disabled centrally, per configuration and
  per run like every other group (ADR-043).
- ``get_fal_references`` reads ``sys_file_reference`` only; soft-reference
  tracking (``sys_refindex``) is a possible later extension if "is this
  file really unused?" needs a stronger answer.
