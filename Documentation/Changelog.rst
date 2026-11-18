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

.. _version-0-51-1:

Version 0.51.1 (2026-11-18)
===========================

The A2A ``writes`` artifact names every record a run's tool calls wrote.

- **Writes artifact:** a completed call's records beside its announced
  write are listed in a block of their own: what core wrote along with it,
  and what a listener of the write stored after the call. With AI Label
  installed, a page metadata write to a translated page left a workspace
  version of the translation (on TYPO3 14) that no list named (#1432).
- **Change list:** a move in a draft workspace is named as the version it
  wrote, not as a live move.

Details in the repository :file:`CHANGELOG.md`.
