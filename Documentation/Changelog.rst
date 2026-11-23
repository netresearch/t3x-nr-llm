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

.. _version-0-54-0:

Version 0.54.0 (2026-11-23)
===========================

``create_content_element_draft`` drafts a content element with its child
records, and other extensions can build such elements through the same rule.

- **Child records:** the items of an accordion, card group, icon group, tab,
  timeline or carousel are passed as ``children``, checked against the
  editor's grants and the page TSconfig, written with the element in one
  DataHandler call, listed on the approval card and read back (ADR-324). A
  child table must declare ``hideTable``.
- **More types offered:** a FlexForm is written with its data structure's
  defaults; folder, group and record-backed select columns are left empty
  instead of excluding the type; ``link`` columns are fillable.
- **API:** ``ContentElementComposer`` (``@api``) with ``describe()``,
  ``typesOfferedOnPage()`` and ``dataMap()``. See
  :ref:`ADR-324 <adr-324>`.

Details in the repository :file:`CHANGELOG.md`.
