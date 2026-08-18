.. _adr-130:

===========================================
ADR-130: Capability grants for backend users
===========================================

:Status: Accepted (constraint 3 enumerated the approval surfaces and the
   enumeration was not exhaustive — see :ref:`ADR-131 <adr-131>`)
:Date: 2026-08-06
:Amended: 2026-08-06 by :ref:`ADR-131 <adr-131>`

Context
=======

nr_llm is admin-only end to end: every backend module is ``access =>
admin``, every AJAX action carries ``denyNonAdmin()``, half the builtin
tools require an administrator. :ref:`ADR-117 <adr-117>` withdrew the first
attempt at finer permissions (capability checkboxes) on three verified
findings — no chokepoint for streaming, a polarity that turned enforcement
into a bypass on user-less paths, and inertness inside an admin-only UI —
but left one door open, verbatim: should per-capability permissions become
worth it, *"they should be designed onto AiActorContext — which already
carries the acting identity across the synchronous, queued and
service-account paths"*.

The product decision has now been made: a grant-set (not a role ladder),
nothing without an explicit grant, approval as its own grant outside any
preset, two coarse task grants, and a non-admin editing surface as a
separate follow-up milestone.

Decision
========

**Grants, not roles.** :php:`BackendUserGrant` is a string-backed enum
mirroring :php:`ServiceAccountScope`: each case documents exactly one
enforcement point, there is no wildcard, and a case is only added together
with its consumer — a grant nothing reads is worse than none. Roles are
documentation-level presets (named grant bundles), not code.

**Assignment via TYPO3's own mechanism.** Grants are registered as
``customPermOptions`` and assigned per backend **group** in the be_groups
access lists; enforcement reads ``check('custom_options', …)`` on the live
user. The core check short-circuits for administrators, so "admins hold
every grant implicitly" comes from the platform, not from our code. A
grant is revoked by unticking it — effective with the next request, since
every check reads the live group data.

**Frozen into the actor at the boundary.** ``currentActor()`` captures the
grant set next to the admin flag and group ids; downstream consumers read
the actor, never the ambient user. :php:`AiActorContext::hasGrant()` is
fail-closed: false for service accounts (their mechanism stays scopes) and
for anonymous callers.

The two initial grants:

- ``tasks_use`` — execute an existing task and refresh its input data
  (the two ``TaskExecutionController`` AJAX actions). The per-user budget
  pre-flight (audit REC 4) bounds what a grant holder can spend.
- ``agent_approve`` — decide OTHER users' suspended runs, as a new branch
  in :php:`AiActorContext::mayActOnRun()`; the human sibling of the
  ``agent:approve`` service-account scope. Deliberately the only scope
  with a grant equivalent — everything else stays owner-or-admin.

Named constraints, each verified against the code
=================================================

1. **No colons in grant values.** TYPO3 strips ``:|,`` from custom
   permission item keys when rendering the be_groups select
   (``TcaItemsProcessorFunctions::populateCustomPermissionOptions()``); a
   colon-namespaced value would be stored mangled and every check would
   silently deny. The values are therefore underscore-separated
   (``tasks_use``), breaking the naming symmetry with
   ``ServiceAccountScope`` (``agent:approve``) on purpose.
2. **The AJAX endpoints go live immediately.** AJAX routes bypass the
   module access check (that is why ``denyNonAdmin()`` exists, ADR-037).
   Swapping the two task-execution gates to ``tasks_use`` makes them
   reachable for grant holders **now**, without any UI — the intended end
   semantics, bounded by the per-user budget. This ADR must not be read as
   "inert until the editing module ships".
3. **``agent_approve`` is reachable for non-admins** — amended, see
   :ref:`ADR-131 <adr-131>`. This record called the grant doubly
   unreachable because it named two human approval surfaces and both sat
   behind admin gates.

   Both gates still hold. ``nrllm_runs`` is still ``'access' => 'admin'``
   in ``Configuration/Backend/Modules.php``, and the Playground's
   ``resumeAction()`` and ``submitInputAction()`` still open with
   ``denyNonAdmin()``. The editing module added a *third* surface that
   did not exist when this was written: ``nrllm_aitasks`` is
   ``'access' => 'user'`` and registers ``AgentRunController::approve``
   and ``submitInput``. A non-admin whose group has that module ticked
   **and** who holds the grant therefore decides other users' suspended
   runs today — which is what the grant is for. Both switches are
   required, and neither substitutes for the other
   (:ref:`ADR-131 <adr-131>` decision 2): the module tick alone grants no
   execution, and the grant alone reaches no surface.

   This record named its own expiry trigger. The sentence that stood here
   said the ``mayActOnRun()`` branch "only becomes exercisable for
   non-admins with the editing module" — and then the editing module
   shipped, with nothing carrying that prediction into the change that
   fired it. So the failure is not that the trigger went unforeseen; it
   is that a foreseen trigger left no mark anywhere a change had to pass.
   **A constraint that enumerates surfaces is only as durable as the
   enumeration.** "Both surfaces are gated" is a claim about a list, and
   a list stops being complete the moment someone registers the next
   entry, whether or not the record predicted it. Nothing in the codebase
   held the list.

   A constraint phrased against the *check* is falsifiable where one
   phrased against the *inventory* is not — but "no approval action sits
   outside an admin gate" is not the check to write, because this record
   deliberately made it false. What distinguishes an intended non-admin
   surface from an accidental one is the inventory written down and
   asserted, which
   ``Tests/Unit/Configuration/ApprovalSurfaceInventoryTest.php`` now
   does. It holds three lists, and each covers a
   bounded scope rather than the whole idea of an approval surface. It
   pins which modules register ``approve``/``submitInput`` and under
   which ``access``. It pins which classes *under* ``Classes/Controller``
   reach :php:`AgentRuntime::approve()` or
   :php:`AgentRuntime::submitInput()`. And it requires every AJAX route
   targeting one of three named actions — ``resumeAction``,
   ``submitInputAction``, ``approveAction`` — to open with
   ``denyNonAdmin()``. It then requires this paragraph to name each
   module the scan finds, matched as a whole identifier rather than as a
   substring, so a name that merely extends one already written here
   does not satisfy it. No example of such a name is given, deliberately:
   writing one into this paragraph would be enough to satisfy the check
   for it.

   A fourth surface fails whichever of the three it falls inside.
   Registered as a module, it fails the module list and this paragraph's
   naming check — under any controller, not only ``AgentRunController``.
   Written as a class under ``Classes/Controller`` that calls the
   runtime, it fails the controller list before it is registered
   anywhere. Exposed as an AJAX route on one of the three named actions,
   it fails unless that route guards itself. Whichever list it broke has
   to be edited; the module door additionally requires this text. Each
   of those failures was produced on purpose, by registering a fourth
   surface of that shape and watching the assertion fail, before this
   paragraph was allowed to claim it.

   What none of the three catches, stated so nobody reads more into them
   than they hold: an approval-calling class outside ``Classes/Controller``,
   exposed under an action name that is not one of the three. That
   combination was tried and the suite stayed green. Widening the scan to
   every class in ``Classes`` would close it and was not done here — the
   check exists to make the enumeration in this paragraph falsifiable, not
   to become a second registry.

   What bounds the grant is therefore not module access alone. Three
   checks apply to a single approval, in the order
   :php:`ResumeCoordinator::approve()` evaluates them. **This list
   describes the approve path only** — ``submitInput`` is the other
   action this constraint names, and its gates are set out after the
   list rather than merged into it, because the middle check does not
   exist there and a merged list of three would be false for it:

   - :php:`AiActorContext::mayActOnRun()` decides whether this actor may
     act on this run at all — owner, admin, or ``agent_approve`` holder.
     A refusal is ``RunAccessDeniedException``.
   - Only on a configuration with ``require_second_approver`` set, an
     *approval* (never a denial) from the run's own initiator is refused,
     admin and grant holder alike (:ref:`ADR-172 <adr-172>`). This is
     opt-in and narrows who may release one particular run
     (``ResumeCoordinatorFourEyesGateTest``).
   - :php:`approverRefusal()` resolves the approver's live backend user
     and asks :php:`ToolCallPolicyInterface::decide()` about every
     pending call that declares a write, throwing
     :php:`ApproverNotPermittedException` on a denial
     (:ref:`ADR-133 <adr-133>`). Unlike the previous check this one is
     not opt-in: ``approve()`` calls it for every approval decision, and
     only a *denial* skips it, because a denial executes nothing. Two
     things scope it — it looks at pending calls that declare a write, so
     a read-only turn passes it unexamined, and the ``requiresAdmin()``
     axis it leans on is one the ``tools.dataClassEnforcement: observe``
     switch does not relax (that switch governs the trust-zone axis
     only). This is what keeps the grant from becoming a write
     escalation: the grant admits the *decision*, ADR-133 withholds the
     *release* of a write the approver could not run themselves
     (``ResumeCoordinatorApproverGateTest``).

   None of the three consumes the turn. The first two throw above the
   ``claimResume()`` call, so the run is never claimed and simply stays
   ``WAITING_FOR_APPROVAL``; the third claimed the state to read it and
   therefore calls ``release()`` before throwing. ``AgentRunController``
   turns the latter two into a flash and a redirect, so a run one grant
   holder may not release stays decidable by someone who may — the two
   gate tests named above assert exactly that, each re-reading the run
   and finding it ``WAITING_FOR_APPROVAL``.

   None of the three shrinks the set of runs the grant admits a decision
   on: that set is ``mayActOnRun()``'s answer, and the other two act on
   the *outcome* of a decision already admitted. That is why they are
   named here and amend nothing about the grant itself.

   ``submitInput()`` is gated the same way minus the middle check. It
   opens with the same :php:`AiActorContext::mayActOnRun()` call and the
   same ``AGENT_APPROVE`` scope, and it ends at
   :php:`submitterRefusal()` (:ref:`ADR-150 <adr-150>`), the sibling of
   :php:`approverRefusal()` against the submitter's live backend user.
   One rule differs: it asks the policy about **every** pending call
   rather than only those declaring a write, because an input-requiring
   tool declares no write — the input and approval markers are mutually
   exclusive at registration — so a write filter would select nothing
   and the gate would be decorative. There is no four-eyes check on that
   path: :ref:`ADR-172 <adr-172>` refuses a self-*approval*, and
   supplying input is not one.
4. **``tasks_manage`` does not exist yet.** The list/wizard actions have
   no per-action gate to migrate (they are module-gated only), and the
   trait's JSON 403 body is the wrong shape for HTML module actions. The
   grant arrives together with its consumer in the editing-module
   milestone.
5. **The record picker stays admin-only.** ``TaskRecordsController``
   reads arbitrary table rows with only a housekeeping-prefix exclusion —
   no ``tables_select`` check, no denylist for ``be_users``/``sys_log``/
   vault tables. Opening it to ``tasks_use`` without a read-boundary would
   be a data-exfiltration primitive; the denylist (modelled on the tool
   denylist) is a prerequisite the editing-module milestone owns.
6. **Grants are group-scoped.** ``custom_options`` is a be_groups field;
   a user without groups cannot hold a grant.
7. **The ADR-117 findings, answered:** the chokepoints here are concrete
   controller gates and ``mayActOnRun()`` (not a pipeline that streaming
   bypasses); the polarity is deny-on-absence everywhere, including
   user-less paths (``hasGrant()`` is false for service accounts and
   anonymous callers, so the queue worker cannot flip a decision); and the
   inertness concern is exactly why the editing surface is a committed
   follow-up rather than an afterthought.

Consequences
============

- ``AiActorContext::backendUser()`` gains an optional ``$grants``
  parameter and the context serialises/rehydrates the grant set with the
  same fail-closed ``tryFrom`` filtering as scopes (recorded in the API
  snapshot; CHANGELOG entry per the 0.x rules).
- The resume path still reconstructs the run owner without grants
  (``ResumeCoordinator``) — harmless today because nothing on that path
  reads them; anything that ever does will see the fail-closed empty set.
- Recommended presets (documentation, not code): *AI editor* =
  ``tasks_use``; approval is granted separately and deliberately sits in
  no preset.
