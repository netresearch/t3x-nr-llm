.. _adr-131:

===================================
ADR-131: The editor-facing module
===================================

:Status: Accepted
:Date: 2026-08-06

Context
=======

:ref:`ADR-130 <adr-130>` shipped capability grants, and its own constraints
list said why they were not yet observable: every nr_llm module is
``access => admin``, so a gate administrators bypass is inert inside the
admin UI (ADR-117's third finding). The committed follow-up is a surface
non-admins can actually reach.

Decision
========

One new backend module, ``nrllm_aitasks`` ("AI Tasks"), containing exactly
what the editing role holds: run a prepared task, and decide agent runs
waiting for approval. Everything else — configuration, providers, models,
playground, wizards, analytics — stays admin-only.

Four structural choices, each forced by a verified platform constraint:

1. **Top-level in the ``web`` group, not a child of ``nrllm``.** The module
   menu drops every top-level module whose own access check fails; since
   ``nrllm`` is admin-only, any child would be invisible to non-admins no
   matter its own access. Editors work in ``web``, so that is where their
   module lives.
2. **``access => 'user'``, never ``'user,group'``.** TYPO3 v14 resolves the
   access string through a gate registry that knows only ``user``,
   ``admin`` and ``systemMaintainer`` — an unknown string denies everyone,
   administrators included. ``'user'`` means the module must be ticked in
   the be_groups module list; the ADR-130 grants are checked per action on
   top. Two switches, both required, both documented.
3. **The approvals inbox is the SAME controller, not a copy.**
   :php:`AgentRunController` (stale-review digest, schema coercion, the
   exception map) is registered in both modules. Visibility is
   actor-scoped in one place: an admin or an ``agent_approve`` holder sees
   every run, everyone else only their own (a new optional ``beUser``
   filter on the run queries). The list filter is a viewport — the write
   side stays independently authorised per run by
   :php:`AiActorContext::mayActOnRun()`.
4. **The task surface is a NEW slim controller**
   (:php:`AiTaskController`), because the admin execute form cannot be
   reused as-is: it renders FormEngine edit links for four tables an
   editor cannot touch, and it embeds the database record picker. The
   editor templates share the ``data-task-execute`` contract, so
   ``TaskExecute.js`` is reused unchanged (its element lookups are
   null-safe where the picker is absent).

What stays out, and why
=======================

- **Tasks with the ``table`` input type are filtered from the editor
  list** (and their execute form redirects like an unknown uid, so a
  guessed id leaks nothing). The record-picker endpoints read arbitrary
  tables with only housekeeping exclusions — no ``tables_select`` check,
  no sensitive-table denylist — and stay admin-only (ADR-130). Wiring
  :php:`TableReadAccessService` into the picker would also restrict
  administrators, which is its own decision, not a side effect of this
  module.
- **``tasks_manage`` still does not exist.** This module adds no
  management surface, so the grant would still have no enforcement point.
- **"Own runs" for editors is mostly the approver's view.** Task
  executions do not create agent runs (they are usage rows); agent runs
  are currently started from admin surfaces. The ownership filter matters
  the moment any non-admin path starts runs, and costs nothing now.

Consequences
============

- Both ADR-130 grants become observable: ``tasks_use`` through the task
  list/run form, ``agent_approve`` through the shared approvals inbox
  (previously doubly unreachable for non-admins).
- The inbox infobox no longer claims to be admin-only; it states the
  actual visibility rule for the current actor.
- Admin discovery: the overview's Tasks card links to the editor module.
- The gate primitive for HTML module actions
  (:php:`denyWithoutGrantHtml()`) complements the AJAX JSON variant from
  ADR-130.
