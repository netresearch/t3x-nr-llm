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

.. _version-0-45-0:

Version 0.45.0 (2026-11-13)
===========================

nr-llm can now act as an A2A server: registered Agent2Agent 1.0 clients send
tasks, answer a question the run asks, and, for a profile marked *May write*,
have drafts written into a pinned draft workspace. A configuration can require
a draft workspace for changes, and a run records which records a tool call
changed. Secret-shaped strings, including TYPO3 preview tokens, are now masked
on the paths that skipped the shared catalogue. nr-llm requires nr-vault 1.5.1.
Run the TYPO3 database schema update after updating.

- **A2A server (spec 051, stages 3 and 4):** with ``a2a.server.enabled`` set and
  a site that names ``nrllm.a2a.baseUrl``, registered clients reach an agent
  card and the JSON-RPC methods ``SendMessage``, ``GetTask``, ``ListTasks`` and
  ``CancelTask``. A client is a record with a bearer token (stored as a hash)
  and runs its tasks as a technical backend user in its pinned workspace. A task
  whose run asks a question is ``INPUT_REQUIRED``; the client answers with a
  ``SendMessage`` that names the task. A profile marked *May write* may use the
  profile's writing tools, which write drafts into the client's pinned draft
  workspace only. The server role is off by default and accepts a task only
  while a worker can be shown to run. New tables ``tx_nrllm_a2a_client``,
  ``tx_nrllm_a2a_profile``, ``tx_nrllm_a2a_dedup``, ``tx_nrllm_a2a_wait_slot``
  and ``tx_nrllm_a2a_write``.
- **Durable decisions (ADR-281):** a decision on a waiting run can be stored and
  carried out by the queue worker; the two paths cannot both win one pause.
- **Draft workspace for changes (ADR-293):** a configuration can require a draft
  workspace (``require_draft_workspace``, off by default). With it on, a write
  by a run outside a draft workspace is refused before an approval card is
  shown and again right before the call runs, administrators included.
- **Changes of a tool call (ADR-292):** a run records a ``tool_changes`` step
  that lists the records the call wrote, also when the call then fails, and the
  approval card states workspace, workspace title and language of the call.
- **Secret shapes masked on more paths (ADR-123):** the ``probe_url`` and
  ``get_last_exception`` reports, MCP tool results, A2A delegation, the
  Responses API failure message, specialized-service errors and log lines, the
  provider connection test, the stored conversation transcript, the Tool
  Playground guardrail reason and embedding input now pass through the shared
  catalogue. The approval card of ``fetch_external_url`` still shows the real
  URL to the approver.
- **nr-vault 1.5.1** is required: its catalogue masks TYPO3 workspace preview
  tokens (``ADMCMD_prev=<token>``).
- **Fixes:** the queue worker refreshes the TYPO3 clock before every queued run,
  a failed or cancelled run reaches the A2A task view, and the A2A task store
  keeps a string under a structural member only in the member's protocol form.
