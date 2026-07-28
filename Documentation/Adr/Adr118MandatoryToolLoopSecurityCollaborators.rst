.. include:: /Includes.rst.txt

.. _adr-118:

============================================================================
ADR-118: Mandatory security collaborators for the tool loop
============================================================================

:Status: Accepted
:Date: 2026-07-28
:Authors: Netresearch DTT GmbH

.. _adr-118-context:

Context
=======

:php:`ToolLoopService` accepted its security collaborators as nullable
constructor parameters with :php:`null` defaults: the composite tool gate
(:php:`ToolCallPolicyInterface`, :ref:`ADR-094 <adr-094>`), the
per-configuration allow-list resolver (:php:`AllowedToolsResolver`,
:ref:`ADR-093 <adr-093>`) and the input schema validator
(:php:`JsonSchemaValidator`, :ref:`ADR-105 <adr-105>`). The nullability
existed for one reason only — the lean test wiring — and production autowiring
always injected all three.

That shape is an implicit fail-open. Without the gate the loop fell back to
three inline filters that evaluated no trust-zone axis
(:ref:`ADR-113 <adr-113>`), logged no denials and persisted no governance
events; without the validator :php:`resumeWithInput()` skipped its
defence-in-depth re-validation entirely. A single wiring regression — an
exclude added to ``Services.yaml``, a refactor that breaks an autowire type —
would silently degrade the security posture while every test stayed green,
because the tests deliberately exercised the degraded path.

.. _adr-118-decision:

Decision
========

**Make the security collaborators required constructor parameters and delete
the wiring-absence fallbacks.**

- :php:`ToolCallPolicyInterface $toolPolicy` and
  :php:`JsonSchemaValidator $schemaValidator` are non-nullable with no
  default; the inline fallback gates in :php:`resolveOfferedNames()` and the
  ``!== null`` guard in :php:`resumeWithInput()` are removed. Behaviour with
  wired collaborators is unchanged.
- :php:`$allowedTools` and :php:`$availability` are **removed** from the
  constructor rather than made required: their only reads were inside the
  deleted fallback, and the required :php:`ToolCallPolicy` already composes
  both gates itself (`ToolCallPolicy.php`). Keeping them would be dead,
  contradictory state.
- Tests build the loop through the new :php:`Testing\ToolLoopBuilder`, which
  defaults to :php:`Testing\NullToolCallPolicy` — a fake that replicates the
  deleted fallback gates verbatim (enabled ∩ requested, fail-closed admin
  RBAC, optional per-configuration intersection; no trust-zone axis, no
  logged or persisted decisions) — and the real, dependency-free
  :php:`JsonSchemaValidator`. Both live in the DI-excluded
  ``Classes/Testing/`` namespace, so production can never silently receive
  the lean gate.
- ``Tests/Functional/Service/Tool/ToolLoopServiceWiringTest.php`` pins the
  production wiring against the real container, following the
  ``GuardrailContainerFloorTest`` precedent (:ref:`ADR-106 <adr-106>`).

Other collaborators (:php:`skillInjection`, :php:`snippetComposer`,
:php:`contextWindow`, :php:`governanceEvents`, :php:`logger`) stay optional:
their absence degrades a feature, not a security gate.

.. _adr-118-consequences:

Consequences
============

- **Breaking for out-of-tree constructors.** Code that instantiated
  :php:`new ToolLoopService(...)` directly must either switch to DI (the
  public :php:`ToolLoopServiceInterface` alias is unchanged) or migrate to
  :php:`Testing\ToolLoopBuilder`. No such construction exists in the
  documented examples; DI consumers are unaffected.
- **Production ``Services.yaml`` is unchanged**: all required collaborators
  already autowired. A future wiring regression now fails the container
  compile (and the wiring test) instead of silently downgrading the gate.
- **The governance seam is always armed**: every denial on every entry point
  is logged and persisted; the trust-zone ceiling applies to every run.
- **Sibling deferred with rationale**: :php:`AgentRuntime` keeps its nullable
  :php:`?JsonSchemaValidator` because its fallback constructs the *real*
  validator (``$this->schemaValidator ?? new JsonSchemaValidator()``) — there
  is no fail-open to close, only a convenience default.
