.. include:: /Includes.rst.txt

.. _adr-117:

============================================================================
ADR-117: Withdraw the backend capability permissions
============================================================================

:Status: Accepted
:Date: 2026-07-27
:Supersedes: :ref:`ADR-023 <adr-023>`
:Authors: Netresearch DTT GmbH

.. _adr-117-context:

Context
=======

:ref:`ADR-023 <adr-023>` registered every :php:`ModelCapability` case as a native
TYPO3 backend group permission and shipped :php:`CapabilityPermissionService` to
resolve the check. It deliberately stopped there: the ADR states that it ships
"the registration + check primitive" and does **not** gate existing calls, and
lists injecting the check into the feature services as a rejected alternative
for that release.

The follow-up never came. Every backend group record therefore shows eleven
checkboxes — chat, completion, embeddings, vision, streaming, tools, JSON mode,
audio, image, text-to-speech, transcription — that look like an access control
and change nothing. An administrator who unticks "vision" for an editor group
gets no warning and no effect.

.. _adr-117-decision:

Decision
========

**Remove the registration, the service and its interface rather than wiring the
check in.**

Three findings decided it, each verified against the code rather than inferred:

**There is no chokepoint to gate.** The middleware pipeline covers chat,
completion, embedding, vision, tools and every specialized call, but streaming
is routed to :php:`StreamingDispatcher` instead and bypasses the pipeline
entirely. ``ModelCapability::STREAMING`` — the capability an operator is most
likely to withhold for cost reasons — would be structurally unenforceable, so
the control would be incomplete by construction.

**The check's polarity turns enforcement into a bypass.**
:php:`CapabilityPermissionService::isAllowed()` returns :php:`true` when no
backend user is present. The queue worker never populates
:php:`$GLOBALS['BE_USER']` — :php:`ActingBackendUserResolver` builds a detached
user object instead — so the same run would be denied synchronously and allowed
after :php:`enqueue()`. Reversing the polarity instead denies every user-less
path: service-account runs, runs whose initiator was deleted or disabled, and
the evaluation commands. :php:`ServiceAccountScope` has no capability-shaped
case, so a CLI job cannot be granted "may use embeddings" at all.

**It would be inert where the UI implies it applies.** All thirteen nr_llm
backend modules are :php:`'access' => 'admin'` and administrators bypass the
check by definition, so unticking a capability would change nothing inside
nr_llm's own interface. Only third-party consumers would be affected — a
half-enforcement that is harder to reason about than no enforcement.

.. _adr-117-decision-alternatives:

Alternatives considered
-----------------------

**Wire the check in behind an opt-in switch, with an upgrade wizard that
pre-ticks all eleven boxes for existing groups.** The migration shape exists in
this codebase (:ref:`ADR-115 <adr-115>` and
:php:`DataClassEnforcementDefaultUpdateWizard`) and would work. Rejected on
value, not on feasibility: it would buy an incomplete control over third-party
consumers only, at the cost of a new chokepoint, a wizard, and an answer for
every user-less execution path.

**Keep the checkboxes and relabel them as reserved.** Rejected: a control that
has to be labelled "has no effect" is worse than its absence.

.. _adr-117-consequences:

Consequences
============

- **Breaking for consumers.** :php:`CapabilityPermissionService` and
  :php:`CapabilityPermissionServiceInterface` are removed, along with the DI
  alias and the ``Documentation/Developer/CapabilityPermissions.rst`` guide that
  described their use. A consumer injecting either must drop the dependency.
  Nothing inside nr_llm called them.
- **The checkboxes disappear from the backend group form.** Values previously
  ticked remain in ``be_groups.custom_options`` as inert strings
  (``nrllm:capability_*``). They are never read again; no migration removes
  them, because an unknown entry in that field has no effect.
- **Access control is unchanged**, because there was none to change. The gate
  that does work stays what it was: the per-configuration ``allowed_groups``
  relation, enforced at runtime by :php:`LlmConfigurationService::hasAccess()`
  and :php:`ConfigurationResolver` (:ref:`ADR-070 <adr-070>`).
- **The door stays open.** Should per-capability permissions become worth it,
  they should be designed onto :php:`AiActorContext` — which already carries the
  acting identity across the synchronous, queued and service-account paths —
  rather than onto the ambient superglobal this ADR removes.
