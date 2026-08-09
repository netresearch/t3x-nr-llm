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

.. _adr-135-second-writer:

Amendment: the second writer, and the language question it raised
==================================================================

``set_file_alternative_text`` sets ``sys_file_metadata.alternative`` for one
``sys_file`` uid. It is the trigger this ADR named under "Revisit when", so the
answer belongs here rather than in an ADR of its own: nothing below changes a
decision above, it applies them to a second table and settles the one question
the first tool never had to ask.

What it takes over unchanged
----------------------------

``IDEMPOTENT_WRITE`` and therefore the approval pause; ``isEnabledByDefault()``
false; ``requiresAdmin()`` false; the ``editing`` group; the live-workspace
restriction; the backend-environment refusal; the read-back verification; the
``ToolPreviewInterface`` before/after (:ref:`ADR-136 <adr-136>`); one neutral
refusal string shared with the READ tool of the same records
(``read_fal_asset_meta``), so a refusal never confirms that a uid exists.

Where it differs, and why
-------------------------

**The authorisation axis is FAL, not the page tree.** Access is decided by
:php:`FalStorageGate::isFileAccessible()`: the configured storage allow-list,
intersected for non-admins with their file mounts. That allow-list is nr_llm's
own configuration and the DataHandler has never heard of it, so the gate must
run BEFORE the write — not instead of it.

That gate does not decide both halves against the same user, and this tool is
the first caller for which the difference is reachable. The storage allow-list
is intersected with the **explicit** acting user's storages
(:php:`BackendUserAuthentication::getFileStorages()`). The file-mount boundary is
not: :php:`isFileAccessible()` asserts on a request-shared
:php:`ResourceStorage`, whose mounts and permissions core's
:php:`StoragePermissionsAspect` attached once from ``$GLOBALS['BE_USER']``. Where
ambient user and acting user coincide — a run its own owner approves — nothing
differs; on an approval by someone else they do. The defect is in
:php:`FalStorageGate`, shared with three READ tools since ADR-047, and is tracked
as issue #672 rather than fixed on this tool.

Core's :php:`FileMetadataPermissionsAspect` then applies the narrower question
inside the DataHandler (a WRITABLE file mount, ``editMeta``), and — this is easy
to get backwards — that aspect can only ever DENY: ``tables_modify`` for
``sys_file_metadata`` still decides first, exactly as it does for the File list
module's metadata form.

**It never creates a metadata record.** A file that carries none is refused. A
tool that creates the record it was asked to edit does more than its name says,
and an invented record is a record nobody reviewed.

**The read-back guard is not reachable on the shipped TCA.** Core marks
``sys_file_metadata.title`` as an exclude field but not ``alternative``, so the
"exclude field" silent drop cannot bite as delivered. The guard stays: the flag
is one TCA override away, and the tool must not report a write that did not
happen. The functional test reaches the path by setting the flag and rebuilding
the compiled schema — mutating ``$GLOBALS['TCA']`` alone no longer changes what
the DataHandler asks in TYPO3 v14.

The language question
---------------------

``sys_file_metadata`` is language-aware (``sys_language_uid`` /
``l10n_parent``); ``pages`` metadata, for the fields the first writer touches,
was not a question at all. Three answers were possible: assume a language, take
one as an argument, or refuse while translations exist.

**Decision: the tool addresses the default language (sys_language_uid = 0)
only, takes no language argument, and refuses when the default-language record
is absent.**

- **Reader and writer must address the same row.** Every FAL read path pins
  ``sys_language_uid = 0`` — ``read_fal_asset_meta`` and ``search_fal_files`` —
  and so does the tool's own :php:`previewCall()`. A writer that could land
  elsewhere would produce changes the model cannot read back and the approval
  card's "before" column could not be trusted to describe the same record. Note
  that ``read_fal_asset_meta`` is admin-only and in the ``structure`` group,
  while this writer is non-admin and in ``editing``: an editor never gets to
  call it, so for the tool's stated audience the approval card is the channel
  for the current value.
- **A language argument is a model-chosen argument.** It would widen the call
  surface by one value that decides WHICH record is written, needs its own
  ``checkLanguageAccess()`` per value, and fails quietly when wrong — the write
  succeeds, on the wrong translation.
- **Refusing while translations exist would be worse than useless.** It would
  disable the tool's main purpose on every multilingual site and would leak the
  existence of translations through the refusal.
- The default language is still not assumed to be permitted:
  ``checkLanguageAccess(0)`` is asserted against the acting user, because a
  backend user can be restricted to languages that exclude it.

Translated alternative texts stay a backend job. Revisit if a writing tool ever
needs to address a translation — at that point the language belongs in the
argument list of the tool that needs it, not retrofitted into this one.

The duplication this ADR predicted, and what was done with it
-------------------------------------------------------------

It was real: about three hundred duplicated lines across the two files, which is
what SonarCloud's quality gate reported on the second writer's pull request. It
was also, on inspection, entirely **mechanism** — the errands a writing tool runs
around its write, not the decisions it makes about the record.

Mechanism was therefore extracted into
:php:`WritesThroughDataHandlerTrait`, a trait in the same directory, following
the pattern :php:`CollectsEnvironmentTrait` and :php:`ResolvesLanguageLabelTrait`
already set — **not** a base class. A base class would open exactly the
inheritance axis this ADR argued against: a common ancestor invites a common
policy, and the two tools' policies are not common.

Shared, because it only executes:

- the backend-environment refusal (which globals the DataHandler declares) and
  the live-workspace refusal — both describe the PROCESS performing the write,
  which is why :ref:`ADR-136 <adr-136>` already excludes both from the preview;
- surfacing a non-empty ``errorLog`` as an error, bounded in count and length;
- narrowing one table's ``columns`` out of the untyped ``$GLOBALS['TCA']``;
- the two preview-formatting helpers (one-line excerpt, quoted or ``(empty)``)
  and the three constants they need.

Kept per tool, because it decides:

- **The neutral refusal string.** Each writer shares it with the READ tool of the
  same records, so a refusal never confirms that a uid exists. One shared string
  would break that pairing in both directions.
- **The authorisation**, including the language rule above.
- **The field allow-list** and every argument-validation message.
- ``isEnabledByDefault()``, ``requiresAdmin()``, ``getGroup()`` and
  ``getEffect()``. They return the same four values today and are still declared
  twice on purpose: a third writer may be admin-only or non-idempotent, and a
  trait answering for it would turn a decision into an inheritance.
- **The read-back.** Both tools verify their write; what "it took" means is
  theirs — a map of fields against a re-read row versus one column of one record.
- **The row lookup.** The query tail is identical; which restrictions apply — a
  deleted page is gone, a ``sys_file`` has no enable columns at all — is a
  decision about what counts as existing. The second writer also looks its
  metadata row up by ``file`` rather than by uid, so it must pin the workspace
  and an order on top of the language: ``sys_file_metadata`` is workspace-aware,
  and a draft version carries the same ``file`` and the same
  ``sys_language_uid = 0`` as the live row. Core's
  :php:`MetaDataRepository::findByFileUid()` pins the same three.

What remains duplicated after the extraction is under a dozen lines per block,
mostly signatures and the two declaration methods above. That is the floor this
shape has, and buying it down further would mean sharing decisions.

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

◐ ADR-122's idempotency scope stays deferred: this tool needs none, its effect
being idempotent by construction. Its absence is now an observation rather than
a prediction.

◐ The preview did not stay deferred. This ADR argued the approval card already
shows the arguments, which for this tool ARE the new values — true, and half the
comparison. :ref:`ADR-136 <adr-136>` supersedes that sentence: the tool
implements :php:`ToolPreviewInterface` and the card shows the values the write
would REPLACE.

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

That happened: ``set_file_alternative_text`` is the second writer, and the
:ref:`amendment above <adr-135-second-writer>` records what it reused, where it
had to differ, and which mechanism moved into
:php:`WritesThroughDataHandlerTrait`. The next trigger is the **third** writer —
and the question then is whether anything the trait deliberately left per tool
has become common, not whether the trait should grow.

Also revisit if an interactive run ever gains a retry path. The fence's absence
is correct only while "interactive" means "no repeat without a human".
