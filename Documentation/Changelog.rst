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

.. _version-0-43-23:

Version 0.43.23 (2026-11-10)
============================

Installations on TYPO3 14.3.11 should update now: with nr-llm 0.43.18 to
0.43.22, a failed tool write in a draft workspace can make publishing that
workspace delete live content. The release also lets a model record carry its
reasoning profile and fixes two OpenAI reasoning-model failures.

- **TYPO3 14.3.11: a failed write in a draft workspace no longer marks the
  live record for deletion.** When ``attach_file_to_content_element`` or
  ``attach_file_to_record`` (since 0.43.18), ``replace_file_reference`` or
  ``set_page_social_image`` (since 0.43.22) failed in a draft workspace, the
  take-back sent ``delete`` for the version the call had made. TYPO3 14.3.11
  treats that as deleting the live record, so the version became a delete
  placeholder, and publishing the workspace deletes the live record. On TYPO3
  14.3 the take-back now sends core's ``discard`` command. TYPO3 13.4 is not
  affected.
- Updating does not remove a delete placeholder an earlier failed write left.
  Before publishing a draft workspace on TYPO3 14.3.11, check it in the
  Workspaces module for records marked for deletion that no editor deleted,
  and discard those changes.
- A model record can carry its reasoning profile: whether it is a reasoning
  model, which ``reasoning_effort`` values it accepts and whether tool calls go
  through Chat Completions or Responses. The fields win over the bundled table,
  so a new OpenAI model works without a release (ADR-275). Run the TYPO3
  database schema update after updating: the model record has seven new
  columns.
- GPT-6: ``gpt-6.1-terra`` is listed, any other ``gpt-6`` id gets the GPT-6
  family default instead of the unknown profile, and ``max`` effort is clamped
  to ``xhigh``. Stop sequences are no longer sent to OpenAI reasoning models,
  which refuse them with HTTP 400.
