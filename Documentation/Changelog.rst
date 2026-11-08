.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _changelog:

=========
Changelog
=========

This page summarizes the latest release. The development branch also includes
unreleased changes: inspect the `pending changelog fragments
<https://github.com/netresearch/t3x-nr-llm/tree/main/changelog.d>`__ before
assuming a behavior is available in a tagged package.

The `complete changelog
<https://github.com/netresearch/t3x-nr-llm/blob/main/CHANGELOG.md>`__ is the
canonical release ledger. The manual's older highlights are selective and
may omit intermediate versions.

During 0.x, a minor release may introduce breaking changes; patch updates
remain within the minor series. Review migration notes before upgrading.

.. toctree::
   :maxdepth: 1

   Changelog/Index

.. _version-0-43-21:

Version 0.43.21 (2026-11-08)
============================

The file list offers the AI actions on files and folders, a new tool sets an
image's categories from a configured category tree, and agent runs apply the
retention of the provider that actually answered.

- The file list offers the editor actions: **AI actions** in the context menu
  of a file opens the Editor Action Center for that file, and on a folder it
  lists the actions on files, each leading to the batch page with that folder
  as its scope. A file or folder outside the storage allow-list or the
  editor's file mounts gets no entry (ADR-267).
- The writing tool ``set_file_categories`` and its editor action **Set
  categories from the image** choose only existing categories below the root
  named in the extension setting ``vision.categories.rootUid`` and inside the
  editor's category mounts. Without a root the action is not offered and the
  tool refuses every call (ADR-268).
- Agent runs apply the retention ceilings of the provider actually used,
  including fallback and failed attempts, and persisted agent calls bypass
  provider-response idempotency caching (ADR-270). Run the TYPO3 database
  schema update before starting workers.
- Measured quality routing now requires ``routing.qualitySet`` and
  ``routing.qualityFingerprint``; re-evaluate the intended set and copy the
  fingerprint printed by ``nrllm:eval:run``. Without them, quality is unknown
  and the other routing signals remain available.
- The documentation resolves 115 audit findings and documents the Responsible
  AI capabilities, limitations and evidence procedures.
