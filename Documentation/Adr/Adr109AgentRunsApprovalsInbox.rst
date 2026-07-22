.. include:: /Includes.rst.txt

.. _adr-109:

============================================================================
ADR-109: Agent Runs approvals inbox (backend module)
============================================================================

:Status: Accepted
:Date: 2026-07-22
:Authors: Netresearch DTT GmbH

.. _adr-109-context:

Context
=======

The human-in-the-loop suspension features had service plumbing but no operator
UI. A run can suspend WAITING_FOR_APPROVAL (:ref:`ADR-084 <adr-084>`) or
WAITING_FOR_INPUT (:ref:`ADR-105 <adr-105>`); ``AgentRuntime::approve()`` and
``submitInput()`` continue it. Until now the only caller was the admin-only Tool
Playground developer tool — there was no first-class surface for an operator to
find the runs that need a decision and act on them.

.. _adr-109-decision:

Decision
========

Ship a new admin-only backend submodule ``nrllm_runs`` ("Agent Runs") — an
approvals **inbox** focused on the runs that need a human, plus a read-only list
of recent terminal runs for context.

Progressive enhancement, no AJAX
--------------------------------

The page works fully with JavaScript OFF: native ``<f:form>`` POST to
module-route ``controllerActions``, a POST-redirect-GET flush with session flash
messages, native ``<details>`` for long tool arguments, and native form
validation. The one JavaScript module is enhancement only — it moves focus to a
422 error summary reliably across browsers. There is no JSON/AjaxRoutes path and
no content-negotiation dual-path — a deliberate simplification over the
playground's batch-JSON contract.

Authorization is the module ``access => admin`` on all three actions
(``list`` / ``approve`` / ``submitInput``). A module-route action cannot be
reached without it, so ``RequiresBackendAdminTrait`` (whose JSON 403 body would
be wrong for an HTML page) is not used here. **Any** admin may act on any run;
the recorded ``decidedBy`` / ``submittedBy`` uid is audit-only. The CSRF defence
is the backend module route token the ``<f:form action=...>`` URL carries
(validated by the ``RouteDispatcher``), not ``__trustedProperties``.

Four non-negotiable correctness/security properties
---------------------------------------------------

1. **No-JS coercion.** A native ``<form>`` posts every field as a string, but
   the validator is strict and ``submitInput()`` validates verbatim. A
   ``SchemaInputCoercer`` casts the POST to the schema's declared types AND omits
   empty OPTIONAL fields, so a blank optional integer/boolean does not 422 the
   whole submission; a non-numeric string for an integer is left uncoerced so the
   validator rejects it with a clear per-field error. A shared
   ``SchemaPropertyClassifier`` is the single ``type → control`` mapping used by
   both the widget factory and the coercer, so a rendered widget can never drift
   from its coercion.

2. **Never an empty form.** ``InputSchema::isUsable()`` returns ``true`` for a
   scalar top-level schema like ``{"type":"string"}``, which would render an
   empty, unsubmittable no-JS form. The input form renders ONLY for an object
   schema with at least one property; anything else is classified ``unreadable``
   and shows a fail-closed notice, never an empty ``<form>``.

3. **Stale-review binding.** ``approve()`` binds only to the run uuid and reloads
   whatever suspended state is CURRENT — a deny/continuation can re-suspend the
   SAME run with a NEW pending turn, so a stale tab (or a second admin) could
   authorize a call the operator never reviewed. The reviewed turn's digest (a
   SHA-256 of the pending calls) travels as a hidden field; the controller
   recomputes it from the freshly-loaded current state and refuses ``approve()``
   on a mismatch, re-rendering "the pending action changed, please re-review".
   The narrow check→approve window is consciously accepted; eliminating it
   entirely would require a runtime change to ``ApprovalDecision`` and is out of
   scope.

4. **Honest load errors.** The two new persister queries return ``null`` strictly
   on a store error (an empty list only when there genuinely are none), so a DB
   hiccup shows a visible error infobox instead of a silently empty inbox that
   could hide waiting runs.

Accessibility (owner priority)
------------------------------

WCAG 2.1 AA and full keyboard operability are first-class: ``h1 → h2`` section
landmarks → ``h3`` per run; native ``<button type="submit">`` in a labelled
``role="group"``; native ``<details>``; ``<fieldset>``/``<legend>``/``<label
for>`` with native ``required`` (preferred over ``aria-required``) and a visual
``*``; ``aria-describedby`` to a per-field description; a focusable 422 error
summary; the operator's raw input preserved across a 422 re-render; non-colour
status. Tool arguments are Fluid-auto-escaped (never ``f:format.raw``) — a
stored-XSS guard, since they are model-chosen, attacker-influenceable text.

Privacy (ADR-064 reconciliation)
--------------------------------

The approval/input EVENTS record only who/when, never the values. But the
operator must see the pending call arguments to decide, so the view factory
reads the raw ``suspended_state`` directly (admin-only, display-only, escaped,
never re-emitted into an event or log). ``TerminalRunView`` deliberately carries
no suspended-state. This is a bounded, admin-only read, not an ADR-064
violation.

.. _adr-109-consequences:

Consequences
============

- New: ``AgentRunController`` (3 actions), ``WaitingRunViewFactory`` + four view
  DTOs, ``SchemaInputCoercer`` + ``SchemaPropertyClassifier``,
  ``BackendUserUidTrait`` (extracted from ``ToolPlaygroundController`` and shared),
  ``AgentRunStatus::awaitingValues()``, two repository queries
  (``findAwaiting`` / ``findRecentTerminal``) and their fail-soft persister
  wrappers, Fluid templates/partials, the ``nrllm_runs`` module + icon + EN/DE
  XLIFF, and an enhancement JS module.
- No change to ``AgentRuntime``, ``ApprovalDecision``, ``InputSubmission`` or the
  DB schema/indexes — the existing ``status_lookup(status, crdate)`` index serves
  the inbox queries.
- Non-goals: no run detail/inspector page, no cancel control, no
  pagination/filter/search, no per-call approval verdicts (approval is
  turn-level), no nested-object input widget (rendered "unsupported"), no JSON
  path.

See also :ref:`ADR-084 <adr-084>` (approval suspension),
:ref:`ADR-105 <adr-105>` (typed input suspension) and
:ref:`ADR-064 <adr-064>` (event privacy).
