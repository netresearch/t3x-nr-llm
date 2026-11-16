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

.. _version-0-49-1:

Version 0.49.1 (2026-11-16)
===========================

An A2A task can send a file from FAL, and three fixes. Run the TYPO3 database
schema update after updating.

- **FAL files for A2A clients:** the tool ``send_fal_file`` (ships disabled)
  sends a file from FAL with a task's result, behind ``a2a.files.enabled``.
  A publication profile must approve its clients as recipients (new field,
  off by default), and the client's technical backend user must reach the
  file through its file mounts and permissions.
- **Workspace mounts:** FAL tools limit a backend user's file mounts to those
  its draft workspace names, as core does.
- **Key rotation:** a master-key rotation reads an A2A file again with a
  locking read before it rewraps it.
- **Push:** a delegating run that registers a push callback takes its first
  look and its suspension at one instant.

Details in the repository :file:`CHANGELOG.md`.
