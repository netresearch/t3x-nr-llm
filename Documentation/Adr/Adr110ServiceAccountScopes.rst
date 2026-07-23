.. include:: /Includes.rst.txt

.. _adr-110:

============================================================================
ADR-110: Service account scopes
============================================================================

:Status: Accepted
:Date: 2026-07-23
:Authors: Netresearch DTT GmbH

.. _adr-110-context:

Context
=======

Every stateful entry point now carries an explicit
:php:`AiActorContext` (:ref:`ADR-091 <adr-091>`) instead of reading
``$GLOBALS['BE_USER']``. An interactive caller is a backend user, authorised by
ownership and admin rights. A non-interactive caller — a CLI command, a
scheduler task, a queue worker — has no backend user, so it identifies itself as
a named **service account**.

Until now a service account was trusted for *everything*: ``mayAccessSession()``,
``mayActOnRun()`` and the restricted-configuration gate
(:php:`ConfigurationResolver`) all returned ``true`` for any service account.
That is too coarse. A narrow automation — say a nightly job that only cancels
stale runs — would, the moment it holds a service-account context, also be able
to approve pending runs, read any conversation, and use configurations
restricted to other groups. A single over-broad principal is exactly the
escalation surface the actor context was introduced to close.

.. _adr-110-decision:

Decision
========

A service account carries an explicit, minimal set of **scopes**
(:php:`Netresearch\NrLlm\Domain\Enum\ServiceAccountScope`). Each entry point a
service account can reach checks the one scope it requires; a service account
that does not declare that scope is denied. Backend users are unaffected —
scopes govern service accounts only, and :php:`hasScope()` is always ``false``
for an interactive caller, so an entry point must still combine it with its own
ownership/admin check.

Fail-closed
-----------

``serviceAccount($name)`` with no scopes may do **nothing**. A capability is
granted only by naming it: ``serviceAccount('cli:nrllm:agent:cancel',
[ServiceAccountScope::AGENT_CANCEL])``. There is no wildcard scope, so a new or
mis-declared automation can never acquire a capability it did not ask for, and a
truncated or tampered serialised row drops any value that is not a known scope
(:php:`AiActorContext::fromArray()`).

One scope per enforcement point
-------------------------------

The taxonomy is deliberately small — every case maps to exactly one existing
gate, so there are no unenforced scopes:

- ``agent:approve`` — :php:`AgentRuntime::approve()` / ``submitInput()``
- ``agent:cancel`` — :php:`AgentRuntime::cancel()`
- ``agent:read`` — :php:`AgentRuntime::status()` / ``events()``
- ``conversation:access`` — :php:`ConversationService::send()`
- ``configuration:use`` — restricted-configuration gate

Run operations do not share one scope: an account granted ``agent:cancel`` can
cancel but neither read nor approve. This is why :php:`mayActOnRun()` takes the
required :php:`ServiceAccountScope` rather than deciding one blanket verdict for
all five run methods.

Scopes round-trip with the actor
--------------------------------

The queue persists the full actor with a queued run (:ref:`ADR-102 <adr-102>`)
and rehydrates it in the worker. Scopes are part of that serialisation, so a
service account that enqueues work resumes with exactly the capabilities it
started with — never more.

.. _adr-110-consequences:

Consequences
============

- The single shipped service account (the ``nrllm:agent:cancel`` CLI command)
  declares ``agent:cancel`` and nothing else.
- ``enqueue()`` / ``run()`` are **not** gated by a scope: who may start a run is
  decided by whoever builds the request (a controller behind backend auth, a
  CLI behind shell access), not by a runtime scope check. A dedicated
  ``agent:run`` scope is deferred until a service-account caller actually reaches
  those methods, so no unenforced scope is shipped.
- ``conversation:access`` is a single read-and-continue capability; a finer
  read-only vs write split is deferred until a consumer needs it.
- New service-account callers must declare their scopes explicitly — a scopeless
  account failing closed is the intended behaviour, not a regression.
