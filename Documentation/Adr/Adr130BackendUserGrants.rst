.. _adr-130:

===========================================
ADR-130: Capability grants for backend users
===========================================

:Status: Accepted
:Date: 2026-08-06

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
3. **``agent_approve`` is doubly unreachable for non-admins today** — both
   human approval surfaces sit behind admin gates (the AgentRun module's
   ``access => admin``, the Playground's ``denyNonAdmin()``). The
   ``mayActOnRun()`` branch is real, tested code, but only becomes
   exercisable for non-admins with the editing module. Stated here so
   nobody reads it as an already-active control.
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
