.. include:: /Includes.rst.txt

.. _adr-171:

============================================================================
ADR-171: The personas nr_llm's code already assumes
============================================================================

:Status: Proposed
:Date: 2026-08-13
:Authors: Netresearch DTT GmbH

.. _adr-171-context:

Context
=======

Issue `#768` blocks a management grant on questions that are not about code:
whether a customer has a person who writes AI prompts but must not hold API
keys, whether an editor's lead needs to see their team's spend. Those questions
cannot be answered from the repository, and every attempt so far has answered
them by shipping a gate.

That has already cost once. :ref:`ADR-023 <adr-023>` shipped eleven backend-group
checkboxes for a person who could not reach the product;
:ref:`ADR-117 <adr-117>` removed them three months later. The section
:ref:`below <adr-171-failure>` states what that episode assumed about people.

This record does not decide who our users are. It states which people the code
**already** assumes, separates that evidence from the assumptions layered on
top, and lists what a human has to answer. Every recommendation in it is open.

**Method.** Personas are named on TYPO3's own role vocabulary wherever one
exists, and listed only where a gate, a module registration or a shipped
documentation claim points at one; none was invented to fill a gap. The "code"
half of each entry is citation-backed. The "human" half is unvalidated, in every
entry, without exception.

.. _adr-171-personas:

Personas
========

.. _adr-171-p1:

1. Administrator (TYPO3 ``Administrator``)
------------------------------------------

**Code.** Fifteen of the sixteen registered backend modules are
``'access' => 'admin'`` — ``grep -c "'access' => 'admin'"
Configuration/Backend/Modules.php`` returns 15. Forty ``denyNonAdmin()`` call
sites in fourteen controllers restore that line on AJAX routes
(:ref:`ADR-037 <adr-037>`, enumerated with its own grep in
:ref:`ADR-169 <adr-169-q6>`). ``isAdmin`` short-circuits :php:`hasGrant()`,
:php:`mayAccessSession()` and :php:`mayActOnRun()`, and bypasses the
configuration group restriction (:php:`ConfigurationResolver::actorMayUse()`).
The
docblock records where that comes from: "Administrators hold every grant
implicitly (the core ``check()`` short-circuits on isAdmin)"
(:php:`BackendUserGrant`'s class docblock) — the platform's rule, not ours.

**Human (unvalidated).** One person owning the instance's AI capability end to
end: holds the vault key, chooses providers and models, decides which tools exist
and which groups may use them, and personally does everything not yet delegable.
Afraid of an unbounded bill and of instance data reaching a provider.

**Confidence.** Enforced by code.

**Blocks.** `#768` question 1. The forty gates protect three unrelated things —
credentials and outbound reach (17 sites), policy binding other people (11), and
an unbuilt read boundary (``TaskRecordsController``, 3; :ref:`ADR-130 <adr-130>`
named constraint 5). One admin bit cannot tell them apart.

**Point at this if you disagree:** the person holding the OpenAI key is the same
person who decides which tools an editor may run.

.. _adr-171-p2:

2. Editor (TYPO3 ``Editor`` plus the ``tasks_use`` grant)
---------------------------------------------------------

**Code.** ``nrllm_aitasks``, ``'parent' => 'web'``, ``'access' => 'user'``
(``Configuration/Backend/Modules.php#nrllm_aitasks``) — the only non-admin
surface in the extension. Two switches are required: the module tick in
``be_groups`` and the grant. The grant has **ten** enforcement points in four
classes — ``grep -rc "BackendUserGrant::TASKS_USE" Classes/`` locates them in
:php:`TaskExecutionController` (3), :php:`EditorActionController` (4),
:php:`AiTaskController` (2) and :php:`EditorActionItemProvider` (1). The module
withholds what an editor must not reach: no FormEngine links, no record picker,
``table``-input tasks filtered out (:php:`AiTaskController::executableTasks()`).
Entry is from the record's own context menu.

**Human (unvalidated).** Works on one record and wants one thing done to it, does
not know what a provider is, and is afraid of publishing something wrong under
their own name.

**Confidence.** Enforced by code.

**Blocks.** :ref:`ADR-119 <adr-119>`'s reopening, which
:ref:`ADR-169 <adr-169-q6>` recommends and does
not do. ADR-119 argued *for* leaving the modules under Administration because
"an editor would never open an nr_llm module"
(:ref:`ADR-119 <adr-119-context-editor>`); the context-menu
item now sends them into one.

**Point at this if you disagree:** :php:`AiTaskController::executableTasks()`
offers **every** active non-``table`` task in the instance to **every** grant
holder. There is no
per-task, per-category or per-group scoping, and no task ownership field.

.. _adr-171-p3:

3. Approver (``agent_approve``)
-------------------------------

**Code.** :php:`BackendUserGrant::AGENT_APPROVE`; the grant branch in
:php:`AiActorContext::mayActOnRun()` — "deliberately the only scope with a grant
equivalent" (:ref:`ADR-130 <adr-130>`, Decision); the write side in
:php:`ResumeCoordinator::approve()` and :php:`ResumeCoordinator::submitInput()`;
the list viewport in :php:`AgentRunController::listAction()`. It sits in no
recommended preset: "granting it is an explicit trust decision"
(``Documentation/Administration/Permissions.rst``). The card renders the tool's
wire name and its pretty-printed ``argumentsJson``
(``ApprovalControls.html#argumentsJson``), and
states its own limits — "Captured when the run paused — the target may have
changed since", "No preview — you are deciding without one".

**Human (unvalidated).** Decides somebody else's proposed write without editing
it, and can predict a tool call's effect from its arguments.

**Confidence.** Enforced by code; the population may be empty — see
:ref:`invented personas <adr-171-invented>`.

**Blocks.** Whether non-admin approval is offered at all, and
:ref:`contradiction A <adr-171-contradictions>`.

**Point at this if you disagree:** the approver may release a write to a record
they hold no rights on, and the preview is withheld exactly then
(:php:`WaitingRunViewFactory::buildApproval()`); the run detail page is withheld
too, because ``AGENT_READ`` has no grant equivalent
(:php:`AgentRunController`'s class docblock).

.. _adr-171-p4:

4. System maintainer (TYPO3 ``systemMaintainer``)
--------------------------------------------------

**Code.** ``grep systemMaintainer Classes/`` returns **zero hits**. nr_llm never
checks this role, and every remedy the governance readout names is behind it: the
page routes the reader to "the Install Tool under Settings > Extension
Configuration > nr_llm" (``Resources/Private/Language/locallang.xlf``, rendered
by ``Resources/Private/Templates/Backend/Governance.html``), and the Settings
module is ``'access' => 'systemMaintainer'`` — "stricter than admin"
(:ref:`ADR-169 <adr-169-q7>`, reading core's
``cms-install/Configuration/Backend/Modules.php``).

**Human (unvalidated).** Holds the instance's global switches. May or may not be
the same account as the administrator who reads the governance page.

**Confidence.** Enforced by TYPO3, never checked by us.

**Blocks.** Whether the governance readout needs a "who to ask" line.
:ref:`ADR-140 <adr-140>` refused an apply path on sound grounds, leaving the
reader no route to the fix.

.. _adr-171-p5:

5. Task manager
---------------

**Code.** None. The role exists only as a shipped claim: "What a task may read
and which model and configuration it uses is defined by whoever manages the task
— that is the trust boundary: task managers define, grant holders execute"
(``Documentation/Administration/Permissions.rst``). Nothing checks it:
:php:`TaskExecutionService::execute()` takes ``(Task, string, ?int $beUserUid)`` —
no actor, nothing to check against. ``tasks_manage`` occurs once in ``Classes/``,
in :php:`BackendUserGrant`'s class docblock — which, since
:ref:`ADR-169 <adr-169>` was accepted, records the reservation as retired rather
than pending.

**Human (unvalidated).** Writes the prompt, picks the configuration, and thereby
sets the data reach of everything an editor runs — without holding credentials.

**Confidence.** Assumed in prose.

**Blocks.** `#768` questions 1-5, and the entire safety argument for
``tasks_use``, which is literally "somebody else drew the boundary".

**Point at this if you disagree:** today that somebody is the administrator, and
the permissions page describes a two-party structure the code does not implement.

.. _adr-171-p6:

6. Downstream integrator
------------------------

**Code.** Named first of three stated audiences
(``Documentation/Introduction/Index.rst``) and the only actor with a
machine-enforced contract: ``api-surface.txt#AgentRuntimeInterface`` freezes
:php:`AiActorContext` as the first parameter of every
:php:`AgentRuntimeInterface` method, so no consumer can call the
runtime without constructing an actor. :ref:`ADR-070 <adr-070>` exists because
consumers were calling ``findOneByIdentifier()`` and skipping both guards.

**Human (unvalidated).** Adds AI to their own extension and never owns provider
plumbing. Wants a typed exception rather than a silent fallback when a
configuration is inactive or restricted.

**Confidence.** Assumed in prose; enforced by a test, not by a gate.

**Blocks.** `#769` — whether a third-party use-case pack author is a colleague or
a supplier, and therefore whether pack declarations stay advisory
(:ref:`ADR-168 <adr-168>`) or become gated.

**Point at this if you disagree:** every consumer of this API that we know of is
one of ours. No third-party integrator is evidenced anywhere.

.. _adr-171-p7:

7. Management-grant holder
--------------------------

**Code.** None, by design: "a case is only added TOGETHER with its consumer (a
grant nothing reads is worse than none)" (:php:`BackendUserGrant`'s class
docblock). It was reserved again in :ref:`ADR-130 <adr-130>` and
:ref:`ADR-131 <adr-131>`; all three reservations are now retired.

**Human (unvalidated).** Curates the AI catalogue for their own team while keys
and endpoints stay administrator-only.

**Confidence.** Was required by a planned feature that
:ref:`ADR-169 <adr-169>` section 5 recommended deleting: "Recommendation:
neither name. Close `#691` as answered."

**Blocks.** Nothing any more, and how it stopped is worth recording. ADR-169 was
accepted on 2026-08-18 while this persona was still unvalidated, so section 5's
recommendation carried. If a validated persona 7 turns up later, section 5 is
the decision that was made without them and the one an answer would reopen —
not this paragraph.

.. _adr-171-contradictions:

Contradictions found
====================

**A. A control ADR-130 said was inactive went live** — RESOLVED, `#787`.
ADR-130 constraint 3 read "``agent_approve`` is **doubly unreachable for
non-admins today** — both human approval surfaces sit behind admin gates …
only becomes exercisable for non-admins with the editing module. Stated here so
nobody reads it as an already-active control." It shipped:
``Configuration/Backend/Modules.php#nrllm_aitasks`` registers
``AgentRunController::approve`` and ``submitInput`` under
``'access' => 'user'``. A non-admin who holds the
grant and whose group has that module ticked decides another user's write today.
ADR-130 now carries ``:Amended:`` and a rewritten constraint 3, and
``Tests/Unit/Configuration/ApprovalSurfaceInventoryTest.php`` pins the module
inventory, so a new approval *module* cannot be registered without ADR-130
naming it. It does not cover every shape an approval surface could take;
ADR-130 says which.

**B. One codebase, two answers to "may this person use this model".** The Editor
Action Center refuses a user outside the configuration's allowed groups —
:php:`EditorActionCatalogue::usableDefault()` via :php:`hasAccess()`, with the
comment "a fourth copy of it is the copy that ages". The task list in the **same
module** does not: :php:`TaskExecutionService::execute()` calls
:php:`resolveEffectiveConfiguration($task->getConfiguration())`, which in
:php:`ConfigurationResolver::resolveEffectiveConfiguration()` is
``$configuration ?? …`` — the task's pinned
configuration, returned unchecked; the method has no actor to check with. A task
pinned to a restricted configuration bypasses that restriction.

**C. The bound that justifies the grant is opt-in.** :ref:`ADR-130 <adr-130>`,
Decision: "The per-user budget pre-flight … bounds what a grant holder can
spend."
``Documentation/Administration/Permissions.rst`` states it in its own words, not
this one's — "Every run is pre-flighted against the user's own usage budget and
attributed to them." :php:`BudgetService::checkUserBudget()`: with no
``tx_nrllm_user_budget`` record for that uid, an inactive one, or one with no
limit set, the check returns
``allowed()``. The budget is per user and opt-in; the grant is per group.
Ticking ``tasks_use`` for a group without creating budget records ships an
unbounded spend, and nothing says so.

**D. The approver may decide what they may not read.**
:php:`AgentRunController`'s class docblock — ``AGENT_READ`` has no grant
equivalent, "so the approval grant widens the list but not the detail page"; the
preview is withheld on the same ground
(:php:`WaitingRunViewFactory::buildApproval()`). A coherent
security position and an incoherent human one. Needs a decision, not a patch.

**E. "Operator" is the most-used word for a person in the corpus and is defined
nowhere.** 201 occurrences across 57 of 169 ADR files, counted at ``96ee600d``
and rising with every record that uses the word; no enum case, no gate, no
glossary. It is used for at least three disjoint permission levels: the
administrator configuring records, the ``systemMaintainer`` changing extension
configuration (:ref:`ADR-140 <adr-140>`), and a person with shell and cron
(:ref:`ADR-103 <adr-103-context>`). Meanwhile
``Documentation/Administration/Permissions.rst`` ships a preset called "AI
operator" that is a **non-admin** holding one grant, and says so: "identical to
*AI editor* on purpose".

**F. The enum's own invariant no longer holds.** :php:`BackendUserGrant`'s class docblock:
"Each case maps to exactly one enforcement point — there is no wildcard"; the
``TASKS_USE`` docblock names two. There are nine, across task execution, a module
index, the Editor Action Center and a record context menu. That is a role-ladder
rung, which :ref:`ADR-130 <adr-130>`'s Context promised the design would not
grow.

.. _adr-171-invented:

Personas we may have invented
=============================

These exist because a gate exists, not because anyone does the job. A reviewer
should read this section first.

- **The approver, as a distinct person.** The gate is real and tested. But
  :ref:`ADR-130 <adr-130>` calls it "**deliberately** the only scope with a grant
  equivalent" — a design choice, not an observed org chart. Until
  contradiction A shipped, no non-admin could reach it at all. We built the
  separation before meeting anyone who wants it.
- **The input submitter.** :php:`ResumeCoordinator::submitInput()` calls the
  *same* predicate as :php:`ResumeCoordinator::approve()`, so submitting is the
  approver's grant.
  :ref:`ADR-150 <adr-150>` reasons about them as a distinct actor with a
  different danger model — and no production tool can produce one:
  ``InputPauseCoverageTest::INPUT_REQUIRING_TOOLS = []`` pins the empty list,
  and nothing in ``Classes/`` implements :php:`RequiresInputInterface`.
- **"AI operator."** A documented role whose only distinguishing feature is a
  grant that does not exist, using the corpus's most loaded word to mean its
  opposite.
- **The lead editor.** One clause, once: "and a lead editor needs the same for
  their editors, or for a group" (:ref:`ADR-119 <adr-119-context-editor>`).
  Nothing implements it; budgets have no group dimension.
  :ref:`ADR-157 <adr-157>` is the one surface that tried and
  declined on principle: "A group is not resolvable to an acting backend user …
  A group entry would have been a control with no reader." It is nonetheless
  ADR-119's stated trigger for moving the whole module tree.
- **The run owner.** :php:`AiActorContext::mayActOnRun()` is real, but "owner"
  is a column
  on ``AgentRun``, not a job; every ``tasks_use`` holder becomes one by pressing
  execute. Its concern — a write under your identity because somebody else
  approved it — belongs to the editor, where a human can be asked about it.
- **The anonymous caller and the backend group.** A fail-closed null object
  (:php:`AiActorContext::anonymous()`) and the unit of entitlement
  (:php:`BackendUserGrant`'s class docblock). Both are load-bearing; neither is a person.
- **The service account.** A machine, not a persona — and the counter-example to
  ADR-023: fail-closed from its first line. One exists, ``cli:nrllm:agent:cancel``
  (:php:`CancelAgentRunCommand::execute()`), declaring one of the five scopes.

.. _adr-171-failure:

The documented failure: ADR-023, withdrawn by ADR-117
=====================================================

ADR-023 registered eleven :php:`ModelCapability` cases as backend-group
permissions. Three assumptions about people were built in, and ADR-117 verified
each against the code:

**That capability describes a job.** ADR-023 put the flags on the group rather
than the configuration because "capability is an editor-role concern, not a
configuration concern" (:ref:`ADR-023 <adr-023-alternatives>`). Half the
execution paths have no person on
them: streaming bypasses the pipeline, and the queue worker never populates
``$GLOBALS['BE_USER']`` — so the same run "would be denied synchronously and
allowed after :php:`enqueue()`" (:ref:`ADR-117 <adr-117-decision>`). A
role-shaped control could
not describe a queue.

**That the person being restricted can reach the product.** They could not. "All
thirteen nr_llm backend modules are ``'access' => 'admin'`` and administrators
bypass the check by definition, so unticking a capability would change nothing
inside nr_llm's own interface" (:ref:`ADR-117 <adr-117-decision>`). It was a
permissions model for
a user who did not exist on the system.

**That absence of a person means nothing to restrict.** :php:`isAllowed()`
returned ``true`` with no backend user — the natural code to write when thinking
about *a human being restricted*. Take the caller seriously and "no person" is
the case that most needs denying.

Verdict, :ref:`ADR-117 <adr-117-decision-alternatives>`: "a control that has to
be labelled 'has no effect' is
worse than its absence." The correction was to reason about an **actor**, which
may not be human, rather than a **role**, which always is — and it is why
:php:`AiActorContext` exists.

One consequence is under-read. ADR-130 pushed roles out of code and into
documentation ("Roles are documentation-level presets (named grant bundles), not
code", :ref:`ADR-130 <adr-130>`). The documentation then invented "AI operator"
and "task
managers". **A role nothing enforces is the same defect as a grant nothing reads,
one layer up.**

.. _adr-171-typo3:

What TYPO3 gives us and what it does not
========================================

TYPO3 gives **roles**: three enforcement primitives, all of which nr_llm uses.
``admin`` is the whole default posture. ``user`` on a module means one tick in a
group's module list and nothing more — :ref:`ADR-131 <adr-131>` records the verified trap
that ``'user,group'`` denies everyone. ``systemMaintainer`` we never check but
depend on.

nr_llm adds a fourth axis TYPO3 has no vocabulary for: ``customPermOptions``
grants frozen into :php:`AiActorContext` at the HTTP boundary — a capability, not
a role, and group-scoped. A fifth has no name in either vocabulary: a
configuration's ``allowed_groups`` (:ref:`ADR-070 <adr-070>`). It decides what the
Editor Action Center offers and became a simulated axis only on 2026-08-13
(:ref:`ADR-167 <adr-167>`). It is a permission that looks like a configuration
field. If this work invents one word, this is where it is needed.

TYPO3 gives no **personas**. It attaches no goal, context or fear to any role,
and neither does this codebase: no surface here records why anyone opens it. That
is the gap this record cannot close, and why the section below asks rather than
decides.

.. _adr-171-questions:

What a human must answer
========================

Answerable, specific, and free of anything a non-developer would have to look up.

1. Is the person who holds the AI provider's API key the same person who decides
   which AI actions an editor may perform? If not, who is the second person?
2. Does anyone at a customer write the AI instructions ("tasks") without being a
   TYPO3 administrator? If yes, may they also see the API keys?
3. Is there a person who reviews and releases an AI-proposed change to a page
   somebody else asked for? Do they read the page, or only the proposed change?
4. Can that reviewer be expected to judge twenty pending decisions produced by
   one colleague's single click? One editor action over twenty records produces
   twenty separate decisions today.
5. How does that reviewer learn a decision is waiting? Nothing notifies anyone.
6. Should an editor be able to run every AI task that exists in the installation,
   or only some? If only some, what decides which — the person, their team, or
   the kind of page?
7. Are AI budgets set per person or per team, and who sets the number? Today they
   are per person and opt-in, so a group with no budget records has no ceiling.
8. When a governance finding says a setting is wrong, who changes it — and does
   that person have access to the Install Tool?
9. Is anyone outside Netresearch building on nr_llm's API today?
10. Do customer installations run backend groups per job ("editors", "leads") or
    per department ("marketing", "shop")? Both entitlement axes here are
    group-scoped, and the code cannot tell the two apart.

.. _adr-171-consequences:

Consequences
============

If this record is accepted as it stands:

- Questions 1-10 go to `#768` as its blocking input; nothing here answers them.
- Contradictions A, B, C and F each get their own issue — an approval control
  ADR-130 declared inactive but is live (ADR-130 also needs the amendment), a
  configuration restriction bypassed by a pinned task, an opt-in spend bound sold
  as a guarantee, and an enum invariant the grant outgrew. C is a defect, not
  persona work.
- "AI operator" is removed from ``Permissions.rst`` or given content.
- No grant, module or field is added by the accepting change. This record adds
  nothing enforceable — the ADR-023 lesson applied to itself.

.. _adr-171-revisit:

Revisit when
============

- Any question above is answered. A persona whose answer arrives becomes a
  requirement.
- A non-admin approver exists at a real installation: contradiction A becomes a
  behaviour question, not a documentation defect.
- A tool implements :php:`RequiresInputInterface`: whether the submitter shares
  the approver's grant becomes a decision rather than an inheritance.
- A third-party consumer appears, and question 9 above gets a real answer
  instead of a frozen api-surface file standing in for one.
- **A second extension wants to cite these personas.** They are not nr_llm's
  property — ``nr_ai_search``, ``nr_repurpose``, the Cowriter and the rest share
  the same audience, and this record lives here only because the decisions it
  unblocks (`#768`, `#691`) live here. At that point it moves to a shared home
  and this file becomes a pointer. Citing an nr_llm ADR from a sibling extension
  would create a dependency between two extensions that nobody decided to have.
