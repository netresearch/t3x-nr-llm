.. include:: /Includes.rst.txt

.. _adr-135:

============================================================================
ADR-135: The first writing tool, and the contract it actually needed
============================================================================

:Status: Accepted
:Date: 2026-08-09
:Authors: Netresearch DTT GmbH

.. _adr-135-context:

Context
=======

:ref:`ADR-122 <adr-122>` declined to build a side-effecting tool contract — an
:php:`ActionInterface`, an idempotency scope, a preview — on the grounds that no
tool wrote, and said so literally: reconsider "starting from the tool, not from
this ADR". This is that tool.

The premise of ADR-122 is what expires here, not its reasoning. Its three
observations still hold: the idempotency scope has no reader, the preview has no
display, and promoting :php:`getEffect()` onto :php:`ToolInterface` would break
every third-party tool for no behavioural gain. So the writer arrives as what the
other forty-one builtins are — one class implementing :php:`ToolInterface`, plus
the opt-in :php:`ToolEffectInterface` — and no framework arrives with it.

.. _adr-135-decision:

Decision
========

Ship :php:`UpdatePageMetadataTool` (``update_page_metadata``): set a fixed
allow-list of descriptive fields on exactly ONE page, through the
:php:`DataHandler`, as the acting backend user.

Why this tool and not a generic record editor
---------------------------------------------

A ``update_record(table, uid, fields)`` tool is one tool instead of many, and
that is its only advantage. Its blast radius is the whole TCA: the same call
shape that fixes a meta description can change a page's ``slug``, its
``fe_group``, its ``perms_*`` or a ``be_users`` row. The arguments are
model-chosen and the model is steerable by injected, externally-authored skill
prose (:ref:`ADR-036 <adr-036>`), so "the model would not do that" is not a
control.

A narrow tool moves the decision from runtime to review time. What
``update_page_metadata`` can do wrong is bounded by its allow-list, and the
allow-list is in the diff. A second narrow writer is a second small review; a
generic writer is a permanent one.

The field allow-list
--------------------

``title``, ``subtitle``, ``nav_title``, ``abstract``, ``description``,
``keywords`` — always present — and, when EXT:seo is installed, ``seo_title``,
``og_title``, ``og_description``, ``twitter_title``, ``twitter_description``.

Every entry is a scalar ``input`` or ``text`` column carrying descriptive prose:
no relation, no routing, no visibility, no access control. The static list is
intersected with the live TCA, so an install without EXT:seo is never offered a
field a call could only fail on.

Excluded, with the reason:

``slug``, ``shortcut``, ``url``, ``target``, ``canonical_link``
   They decide where a URL points. A wrong value breaks routing or sends
   traffic somewhere else.

``doktype``, ``hidden``, ``nav_hide``, ``starttime``, ``endtime``, ``fe_group``
   Publication and audience. Unpublishing a page is not a metadata edit.

``perms_*``, ``editlock``, ``TSconfig``, ``backend_layout*``, ``is_siteroot``
   Permission and configuration surface — the things the tool is authorised
   *against*.

``no_index``, ``no_follow``
   They sit in the SEO palette and read like metadata; a single call can
   deindex a site.

``author``, ``author_email``
   A claim about a person, plus personal data. A model must not assert
   authorship.

``og_image``, ``twitter_image``, ``media``
   FAL relations. The DataHandler's relation handling is a different risk class
   than setting a scalar.

``sys_language_uid``, ``l10n_parent``, ``l18n_cfg``
   Translation topology.

One page per call, live workspace only
--------------------------------------

Exactly one ``uid``, so every suspended call has one reviewable subject on the
approval card. Everything but workspace 0 is refused: a draft write belongs to
the workspace publishing machinery, which carries its own review semantics, and
this tool does not silently join them.

An unknown field refuses the WHOLE call rather than applying the known half of
it. A record half-written from a call the model got wrong is harder to reason
about than a refusal the model can correct.

The write refuses without a backend environment (E2)
-----------------------------------------------------

:php:`DataHandler` declares ``$GLOBALS['TCA']`` **and** ``$GLOBALS['LANG']`` as
its dependencies in the class docblock, and :php:`start()` sets only
:php:`DataHandler::$BE_USER` — a foreign hook running inside the write still
reads ``$GLOBALS['BE_USER']``. On a request-bound run all three exist; in a bare
worker process they do not.

**The tool refuses and names which one is missing.** It does not populate the
globals. Establishing an ambient backend user is exactly the thing
:ref:`ADR-083 <adr-083>` removed from this runtime, and a tool that sets globals
it does not own would be setting them for every hook and every later request in
the same process.

Two honest limits of that check. First, for a plain page-field update the core
code path does not itself dereference ``$GLOBALS['LANG']`` — the
:php:`getLanguageService()` call sites are the password-policy, copy-prepend,
localize and flash-message paths. The prerequisite is the class's declaration
and the hook surface, not an observed fatal on this path; the check honours the
declaration rather than betting on the current implementation. Second, requiring
``$GLOBALS['BE_USER']`` to *exist* does not make it the *same* user as the acting
one. A hook that reads the ambient user still reads whoever the request belongs
to. Closing that gap means either mutating the global or auditing every hook —
both larger than this tool, and neither is done here.

Where the write lands in the existing machinery
------------------------------------------------

Nothing new was needed:

- **Approval.** The declared write effect makes every call suspend for a human
  (:ref:`ADR-134 <adr-134>`). The tool carries no approval marker of its own,
  which is the coupling working as designed.
- **The audit.** :php:`AgentRunExecutor::recordStepFailClosedForWrites()` fails
  the run when a writing step's audit event cannot be stored. That guard is
  outside the lease branch, so it holds on every path.
- **Data class.** The new ``editing`` group defaults to ``EDITOR_CONTENT``
  (:ref:`ADR-094 <adr-094>`). Without that entry the group would fail closed to
  ``SECRET_ADJACENT`` and the tool would vanish from every external-provider run
  with no explanation.
- **The coverage test.** ``ToolEffectCoverageTest::DECLARED_WRITERS`` was empty
  and is now one entry long. That is the assertion ADR-122 built for this
  moment.

A group of its own, not ``content``
------------------------------------

``editing`` is new. Putting the writer in ``content`` would give write capability
to every configuration that already grants the read-only content group — a
capability change delivered by an upgrade, in a field nobody edited.

The group gate is not the only layer, and it is not the strongest one: an empty
``allowed_tool_groups`` means "no group restriction"
(:php:`AllowedToolsResolver::applyGroupGate()`), so a configuration that never
restricted groups sees the new tool regardless of which group it is in. That
case is caught by :php:`isEnabledByDefault()` returning ``false`` — the tool is
globally off until an admin enables it in the Tools module — and by the approval
pause. The group split closes the case the enable flag does not: an operator who
deliberately enables the tool for one configuration does not thereby enable it
for every configuration that had listed ``content``.

Success is verified, not assumed
---------------------------------

An empty :php:`DataHandler::$errorLog` is not proof the values landed. The
DataHandler **silently skips** a field the acting user holds no
``non_exclude_fields`` grant for — no exception, no log entry. The tool
therefore re-reads the row and reports any field whose stored value is not the
requested one as an error.

Reporting a write that did not happen is the worst available outcome for a tool
whose entire premise is that a human approved a specific change.

The read-back can only report *that* a field did not arrive, and it names the
one cause it cannot rule out. So the second cause is removed before the write
instead: an empty value for a field the TCA marks ``required`` — ``pages.title``
— is dropped by :php:`validateValueForRequired()` just as silently, and the
argument gate refuses it. Otherwise an admin, who holds every field grant by
definition, would be told they were missing one; and where the stored value
happened to equal the rejected one, the read-back would report success for a
write the DataHandler refused. Clearing an *optional* field stays available: that
write happens, and the read-back verifies it.

.. _adr-135-nonguarantee:

What this does NOT guarantee: the write fence
==============================================

The ADR-112 lease-before-op write fence does **not** protect this tool as
shipped, and no document may claim otherwise.

The fence arms only when a lease owner is present:
:php:`AgentRunExecutor::trace()` installs its ``onBeforeTool`` hook under
``$leaseOwner === null || !$handle instanceof AgentRunHandle ? null : …``. The
only producer of a lease owner is :php:`QueuedRunCoordinator::runQueued()`.

That method **does** have in-repo callers —
:php:`Service\Agent\Queue\AgentRunQueuedHandler::__invoke()` and the reaper's
re-dispatch in :php:`Command\ReapStaleAgentRunsCommand` — so "``runQueued()`` is
unreachable" would be wrong. What has no in-repo caller is
:php:`AgentRuntimeInterface::enqueue()`, and only ``enqueue()`` creates the
QUEUED row those callers can act on. The reaper cannot conjure one either:
``findStaleRunning`` selects on ``lease_expires > 0``, which an interactive run
never has.

Every shipped entry point (the Tool Playground batch and streamed runs, the
approval and input resumes, the CLI) goes through ``run()``, ``approve()``,
``submitInput()`` or ``cancel()``, all of which pass no lease owner.
``pending_effect`` is never stamped for them.

Sharper still for the resume path: :php:`AgentRunExecutor::executeResume()`
passes no lease owner at all, so **no** resume is fenceable however the run was
started.

This is not an accident to be alarmed by, and it is not the transport's doing:
``enqueue()`` on the default ``SyncTransport`` would arm the fence perfectly
well. The gate is ``run()`` versus ``enqueue()``. An interactive run has no
reaper and no retry path, so there is no repeat for a fence to prevent —
a suspended run either resumes because a human pressed Approve, or it does not
resume at all.

What must not be said is "writes are fenced". They are fenced on the queued
path, which only a downstream consumer of the public ``enqueue()`` API reaches
today. The fail-closed audit and the approval pause are the guarantees that hold
everywhere.

.. _adr-135-consequences:

Consequences
============

●● A model can change what a page says about itself, with a human approving each
change and the acting editor's own TYPO3 permissions enforced twice — once by
the tool, once by the DataHandler.

● The write path finally runs. The effect declaration, the fail-closed audit and
the approval coupling had no exerciser; they have one now, and the functional
tests drive them against a real DataHandler.

◐ ADR-122's deferred pieces stay deferred. This tool needed no idempotency
scope (its effect is idempotent by construction) and no preview (the approval
card already shows the arguments, which for this tool ARE the new values). Their
absence is now an observation rather than a prediction.

✕ A downstream consumer that calls ``enqueue()`` gets the fence; every shipped
entry point does not. The gap is named above rather than closed — closing it
means arming the fence on the interactive path, which needs a lease the
interactive path does not have.

✕ The ambient-user gap in the E2 check stands: a foreign DataHandler hook may
still read a ``$GLOBALS['BE_USER']`` that is not the acting user.

.. _adr-135-revisit:

Revisit when
============

A **second** writing tool is proposed. One writer is a class; two writers with
the same permission pre-check, the same refusal vocabulary and the same
read-back verification are a shared base — and at that point the shape of the
contract ADR-122 declined to guess at will be visible in the duplication rather
than imagined.

Also revisit if an interactive run ever gains a retry path. The fence's absence
is correct only while "interactive" means "no repeat without a human".
