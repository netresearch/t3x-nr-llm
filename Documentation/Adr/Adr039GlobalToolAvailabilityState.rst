.. include:: /Includes.rst.txt

.. _adr-039:

==================================================================
ADR-039: Global per-tool availability state
==================================================================

:Status: Accepted
:Date: 2026-06-30
:Authors: Netresearch DTT GmbH

.. _adr-039-context:

Context
=======

The tool runtime (:ref:`ADR-038 <adr-038>`) gates *which* tools a single agent
run may call through two mechanisms:

- each :php:`ToolInterface` declares :php:`isEnabledByDefault()` — a compile-time
  default (e.g. read-only tools ship on, mutating ones ship off);
- every run carries a per-request **allow-list** (the skill's ``allowed-tools``
  or the playground selection), so a run only ever sees the subset it asked for.

What was missing is an **operator control**: an administrator could not globally
turn a registered tool off for the whole instance. A tool shipping
``isEnabledByDefault() === true`` was callable by every run that allow-listed it,
with no site-wide kill switch; and a default-off tool could not be switched on
without a code change. Neither the per-tool default nor the per-run allow-list is
the right seam for "this instance does not permit ``get_env`` at all".

.. _adr-039-decision:

Decision
========

Introduce a **global, per-tool availability override** that sits above the
per-tool default and below the per-run allow-list.

- **Storage** — a dedicated table ``tx_nrllm_tool_state`` (``tool_name`` unique,
  ``enabled`` boolean). It has **no TCA and no FormEngine UI**: it is operational
  state toggled from the backend, not editorial content edited as a record. A
  **missing row falls back to the tool's** :php:`isEnabledByDefault()`, so the
  table only ever holds explicit admin overrides.
- **Repository** — :php:`ToolStateRepository` exposes :php:`overrides()` (the
  sparse override map) and :php:`setEnabled(name, bool)` (upsert one override).
- **Effective-state service** — :php:`ToolAvailabilityService` computes the
  authoritative *"what may run at all"* set: for every registered tool the
  effective state is its admin override when one exists, otherwise its
  :php:`isEnabledByDefault()`. :php:`enabledNames()` returns the enabled subset;
  :php:`states()` returns the full name / description / enabled / defaultEnabled
  rows the backend renders.
- **Runtime enforcement** — :php:`ToolLoopService` **intersects every per-run
  allow-list with** :php:`enabledNames()`, so a globally-disabled tool can never
  be invoked regardless of what a skill or the playground requested. This is the
  same defense-in-depth layering as the acting-user RBAC intersection in
  :ref:`ADR-038 <adr-038>` — the allow-list narrows, it never widens.
- **Backend surface** — the toggles are rendered and persisted by the dedicated
  **Tools** backend module (:php:`ToolController`), split out from the interactive
  **Playground** module so managing availability and running the agent loop are
  separate admin concerns (see the two-module split). :php:`toggleToolAction()`
  is admin-guarded (:ref:`ADR-037 <adr-037>`) and writes through
  :php:`ToolStateRepository::setEnabled()`.

.. _adr-039-consequences:

Consequences
============

- Administrators get a **site-wide kill switch** per tool, independent of code
  defaults and of any individual run's allow-list.
- Availability resolves in two steps: the **effective global state** is the admin
  override when one exists, **otherwise** the compile-time default (so an override
  can enable a default-off tool or disable a default-on one — it *replaces* the
  default, it does not merely narrow it). The **per-run allow-list is then
  intersected** with that effective set, so a run can only ever *narrow* what is
  globally enabled — a globally-disabled tool can never be called, but the
  allow-list can never re-enable one.
- The table is **deliberately TCA-less**: it is a small operational toggle set
  keyed by ``tool_name``, not a versioned/localisable record, so a bespoke
  toggle endpoint is a better fit than FormEngine (and avoids exposing an
  editable "tool" record that implies more than a boolean).
- Because a missing row falls back to the tool default, **shipping a new tool**
  needs no data migration: its :php:`isEnabledByDefault()` applies until an admin
  overrides it.
- Reads go through :php:`ToolAvailabilityService` on every agent run; the override
  map is a single small query, cheap relative to the LLM calls it gates.

.. _adr-039-alternatives:

Alternatives considered
=======================

- **Reuse the per-run allow-list only** — rejected: the allow-list is authored per
  skill/run and cannot express an instance-wide policy; a globally-forbidden tool
  would have to be scrubbed from every skill.
- **Flip** :php:`isEnabledByDefault()` **in code** — rejected: the default is a
  ship-time property of the tool, not per-instance operator policy, and changing
  it requires a release.
- **A TCA-backed ``tool`` record** — rejected: tools are code-registered, not
  editable entities; a full record UI would imply create/delete/localise
  semantics that do not apply to a boolean override keyed by a code identifier.
