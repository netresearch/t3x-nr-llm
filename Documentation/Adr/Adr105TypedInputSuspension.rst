.. include:: /Includes.rst.txt

.. _adr-105:

============================================================================
ADR-105: Typed user-input suspension (WAITING_FOR_INPUT)
============================================================================

:Status: Accepted
:Date: 2026-07-22
:Authors: Netresearch DTT GmbH

.. _adr-105-context:

Context
=======

Human-in-the-loop so far means *approval* (:ref:`ADR-084 <adr-084>`): a tool
opts into a verdict, the run suspends WAITING_FOR_APPROVAL, and ``approve()``
continues it with approve/deny. But some tools need the human to supply
**typed data**, not a verdict — a target the model cannot know, a value only a
person can decide. The P1 roadmap's final queue-epic slice asks for exactly
this: a tool that suspends the run to collect schema-validated input and
resumes with it fed back into the tool's arguments.

.. _adr-105-decision:

Decision
========

A new suspend kind, routed by the persisted status discriminator:
WAITING_FOR_APPROVAL → ``approve()``, **WAITING_FOR_INPUT → ``submitInput()``**.
The two never share a code path after the row is loaded, so the persisted state
needs no "kind" field.

- **Tool signal.** A tool opts in with the ``RequiresInputInterface`` marker
  (the sibling of ``RequiresApprovalInterface``), declaring an input schema via
  ``getInputSchema()``. When the model calls an *offered* such tool, the loop
  throws ``ToolInputRequiredException`` carrying a ``SuspendedRunState`` whose
  new ``inputToolName``/``inputSchema`` fields name the target and its schema.
  The runtime's ladder catches it right after the approval arm (before the
  guardrail pair and the generic ``Throwable``) and persists WAITING_FOR_INPUT.
- **Reuse.** The status is stored in the existing ``suspended_state`` column
  (no new column), the guarded suspend/claim transitions mirror ADR-084
  (``suspendRunForInput`` / ``claimForResumeFromInput``), the lease is cleared
  so the reaper (:ref:`ADR-104 <adr-104>`) ignores a waiting run, and the
  MAX(sequence)+1 resume-position resolution is unchanged.
- **Divergence — validate before claim.** ``submitInput()`` validates the
  submission against the declared schema (via the existing structure-only
  ``JsonSchemaValidator``, :ref:`ADR-082 <adr-082>`) **before** probing or
  claiming the run. An invalid submission is rejected without consuming the
  claim, so the run stays WAITING_FOR_INPUT and the user can resubmit — a flow
  approve/deny has no analogue for.
- **Overlay.** On resume, the human's validated values are overlaid onto the
  target call's arguments **bounded to the schema-declared keys**: the model's
  own values for those keys are stripped and only declared keys from the human
  are merged, so neither side can smuggle a value into the other's field.

.. _adr-105-fail-closed:

Fail-closed rules
=================

- **A degenerate schema is corruption, never accept-all.** An empty/shapeless
  ``inputSchema`` (against which ``validate()`` returns true for anything) is
  rejected at both the capture-time gate (``LogicException``) and the rehydrate
  gate (``CorruptSuspendedStateException``) — one shared predicate
  (``InputSchema::isUsable()``) is the single authority.
- **A tool may not be both approval- and input-gated.** The approval-resume
  path carries no input and would silently drop the mandatory data; the
  combination is rejected at tool registration, and — defence in depth —
  ``resume()`` refuses an input-requiring pending call rather than fail-open
  executing it.
- **An unstorable suspension fails closed** as ``SUSPEND_FAILED`` (as approval
  does): promising an input flow that cannot be resumed would strand the client.
- **Submitted values are untrusted content** entering the model context; the
  submit entry point is admin-gated, which is the injection mitigation
  (structure-only validation does not sanitise content).

.. _adr-105-consequences:

Consequences
============

- ``AgentRunOutcome`` gained ``AWAITING_INPUT``; ``AgentEventKind`` gained
  ``INPUT`` (payload ``{submittedBy}`` only, never the values, ADR-064);
  ``AgentRunStatus::WAITING_FOR_INPUT`` already existed and needed no change.
  All are the documented minor-release growth path — consumers match with a
  default arm.
- ``AgentRuntimeInterface`` gained ``submitInput()`` (an interface method, not a
  new public service — the audited public-service count is unchanged);
  ``AgentRunRepositoryInterface`` gained ``suspendRunForInput()`` and
  ``claimForResumeFromInput()``; ``ToolLoopServiceInterface`` gained
  ``resumeWithInput()``.
- Accepted limitations: validation is structure-only (no min/max/enum/pattern —
  a tool needing those re-checks in ``execute()``, as ``completeStructured``
  does); a single turn requesting two tool inputs is fail-closed-refused on the
  second, not collected; the schema is delivered in the suspend response, not
  via ``status()`` (which strips ``suspended_state``), so a client reloading
  mid-wait loses the form — matching the existing approval ``pendingTools``
  limitation.
