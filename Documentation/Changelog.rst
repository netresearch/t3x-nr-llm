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

.. _version-0-43-22:

Version 0.43.22 (2026-11-09)
============================

The social image and file reference writers work in the editor's draft
workspace, AI-written pages and content elements can carry the b13/ai-label
marker, and the task endpoints refuse inactive tasks and, for editors, tasks
with table input.

- ``set_page_social_image`` and ``replace_file_reference`` work in the
  editor's TYPO3 workspace: in a run started in a draft workspace they give
  the page or content element its version, add the new file reference in that
  workspace and mark the replaced one for deletion there, translations
  included. Live stays as it is until the workspace is published; the
  approval card names the workspace and the answer to the model says the
  change is a draft. ``replace_file_reference`` refuses an element moved in
  the draft workspace until the move is published or discarded (ADR-274).
- The take-back of a failed write in a draft workspace discards only the rows
  the call made and keeps the editor's earlier drafts, also further down the
  record's inline children; the answer names each row that is not as it was
  before (ADR-274).
- Optional b13/ai-label integration for AI-written pages and content
  elements, with acting-user and workspace attribution, origin classification
  and review resets. The nr-llm marker remains available independently.
- The task execute form and the input-refresh endpoint refuse an inactive
  task with "Task is not active". A backend user with the ``tasks_use`` grant
  who is no administrator can no longer run a task with the ``table`` input
  type or refresh its input; both endpoints answer "Task not found".
- GPT-6 model ids get the reasoning profile only as the exact family name or
  with a ``-YYYY-MM-DD`` snapshot suffix. Other ``gpt-6`` ids, such as
  ``gpt-6-sol-latest``, get the conservative unknown profile; configure the
  exact family name or a dated snapshot to keep the reasoning profile.
- Usage statistics distinguish unreported from zero token counts
  (``isPromptTokensReported()``, ``isCompletionTokensReported()``), cached
  answers are screened against the current output policy, and boolean tool
  arguments must be native JSON booleans: string and numeric spellings
  trigger argument correction.
