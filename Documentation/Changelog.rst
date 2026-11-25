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

.. _version-0-55-1:

Version 0.55.1 (2026-11-25)
===========================

Refused drafts reach the model without an approval card, and a model can list
computed choices such as icons.

- **Refused drafts:** a ``create_content_element_draft`` call whose plan
  refuses it no longer waits for approval. A call that skips its approval
  because its tool refuses it is never executed (ADR-328).
- **Choices:** the read-only tool ``list_content_element_choices`` lists and
  searches the names of a computed select of a child record. See
  :ref:`ADR-328 <adr-328>`.

Details in the repository :file:`CHANGELOG.md`.
