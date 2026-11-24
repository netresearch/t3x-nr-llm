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

.. _version-0-55-0:

Version 0.55.0 (2026-11-24)
===========================

Drafts of content elements take icons and FlexForm values.

- **Computed choices:** a child record's select whose items an
  ``itemsProcFunc`` computes, such as bootstrap_package's icons, is chosen by
  name. An unknown name is answered with the nearest ones (ADR-326).
- **FlexForm values:** the simple fields of a type's FlexForm are set through
  ``fields``, checked, shown on the approval card and read back. Fields with
  ``exclude`` are for administrators.
- **API:** ``ContentElementComposer::describe()`` gains ``flexForms``, and
  ``items`` or ``itemsBy`` for computed selects. See
  :ref:`ADR-326 <adr-326>`.

Details in the repository :file:`CHANGELOG.md`.
