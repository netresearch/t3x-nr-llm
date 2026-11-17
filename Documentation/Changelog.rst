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

.. _version-0-50-0:

Version 0.50.0 (2026-11-17)
===========================

An A2A task's file can be taken into a FAL inbox after an approval, a
delegating run asks its initiator when the remote agent asks, two editorial
A2A handlers, and a tool audit trail. Run the TYPO3 database schema update
after updating.

- **Client file into FAL:** the tool ``adopt_client_file`` (ships disabled)
  stores a file an A2A client sent in the one non-public inbox folder its
  publication profile names, as the client's technical user and after the
  approval of a person of this installation (ADR-305). No folder: no
  adoption. A taken name is never overwritten.
- **Questions of a remote agent:** a delegating run no longer hands the
  remote's question to the model; the backend user who started it answers in
  the waiting runs' input form, and the answer goes to the task that asked
  (ADR-311).
- **Editorial texts:** ``nr_llm.editorial_text`` writes and
  ``nr_llm.editorial_check`` checks a text for use outside TYPO3; the answer
  is checked against the configuration's rules before it is stored, and a
  ``report`` artifact carries the result (ADR-301, ADR-302).
- **Runs:** a run that completes after an approval, an answer or a remote
  agent keeps its answer, also without an origin;
  ``AgentRuntimeInterface::cancelIfWaitingForDependency()`` cancels only a run
  that still waits for its dependency. Delegation tools are offered with
  nr-mcp-agent 0.39.0 or later.
- **Tools module:** every switch of a tool or tool group is recorded in
  ``tx_nrllm_tool_audit``; the group toggle works again.
- **Breaking for implementers:** ``AgentRunRepositoryInterface::recordAnswer()``
  takes an optional third parameter, and the interface is now ``@internal``.
- **Fixed:** a scoped write the database committed before the call ended is
  reported as written, so an A2A task's ``writes`` artifact names the draft.

Details in the repository :file:`CHANGELOG.md`.
