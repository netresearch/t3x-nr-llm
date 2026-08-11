.. include:: /Includes.rst.txt

.. _adr-158:

============================================================================
ADR-158: The Editor Action Center adds a catalogue, not a runtime
============================================================================

:Status: Accepted
:Date: 2026-08-11
:Authors: Netresearch DTT GmbH

.. _adr-158-context:

Context
=======

:ref:`ADR-152 <adr-152>` gave every writing tool a human-facing declaration: a
translatable name, a sentence written for a person rather than for a model, an
icon, and the record types the action addresses. It also said, explicitly, that
each thing it did **not** build belongs with the consumer that would read it.

This is that consumer, and until now it did not exist. An editor could not find
an editor action at all. The five writers are reachable only when a model
chooses to call one inside a run, and the only surface that renders the
declarations is the admin Tools module — a management list, not a place to do
work. ``nrllm_aitasks`` (:ref:`ADR-131 <adr-131>`) is the one module a non-admin
can reach, and it offers prepared *tasks* and the approvals inbox. There is no
catalogue and no per-record entry point.

Everything that makes such a surface safe already exists and is load-bearing: a
declared write implies approval (:ref:`ADR-134 <adr-134>`), the loop suspends
and captures a preview in the run's actor context
(:ref:`ADR-136 <adr-136>`), the inbox re-authorises that preview per viewer, the
composite tool gate answers who may call what (:ref:`ADR-094 <adr-094>`), and
the write fence refuses a write on a segment with no persisted run or no lease
(:ref:`ADR-141 <adr-141>`).

.. _adr-158-decision:

Decision
========

   **The Editor Action Center adds a catalogue and one entry point. It adds no
   execution, no approval path and no authorisation rule.**

Three parts, and nothing else.

**A catalogue driven by the declarations and the real gate.**
:php:`EditorActionCatalogue` reads
:php:`ToolAvailabilityServiceInterface::editorActions()`, narrows by the
declaration's ``recordTypes`` when a record is carried, and then asks
:php:`ToolCallPolicyInterface::decide()` for every remaining tool. That gate is
five checks evaluated as one AND — registered, globally enabled, permitted for
this user, inside the configuration's allowed tool groups, inside the trust
zone's data-class ceiling. The catalogue re-implements none of them. A
hand-rolled "is it enabled" here would be the fourth copy of a five-part rule
and would be the copy that ages.

The gate answers against a configuration, so an install with no default LLM
configuration offers nothing. Fail-closed and honest: there would be nothing to
run the action on.

**The configuration itself is a permission, and the tool gate does not check
it.** :php:`ToolCallPolicyInterface::decide()` answers "which tools on THIS
configuration"; it never answers "whose configuration is it". The ``beGroups``
restriction on a configuration means only those backend groups may use it
(:ref:`ADR-070 <adr-070>`), and no later step re-checks it — the run request
carries the configuration straight into the runtime. So the catalogue asks that
question too, once, through
:php:`LlmConfigurationServiceInterface::hasAccess()`: the existing ambient-user
form of the rule, not a fourth copy. An editor outside the default
configuration's groups is therefore offered nothing, and a POST naming an
action directly builds no run.

**One entry point that carries the record.**
:php:`EditorActionItemProvider` adds a single context-menu item on a record,
linking to the catalogue for that record's table and uid. It appears only where
the catalogue is non-empty for that table and that user, so an editor who may
run nothing sees no item rather than an item leading to an empty page. The item
is a link: nothing is started from the menu, and nothing about the record is
read. The declarations' ``recordTypes`` are matched before anything else, and
that match reads the tool registry rather than the database — so a right-click
on a table no editor action addresses costs no query at all.

**Starting an action is an ordinary agent run.**
:php:`EditorActionCatalogue::runRequestFor()` builds a plain
:php:`AgentRunRequest` — the default configuration, one user message naming the
tool and the record, the caller's :php:`AiActorContext`, and an
``allowedToolNames`` of exactly one — and the controller hands it to
:php:`AgentRuntimeInterface::run()`. The declared write then suspends
AWAITING_APPROVAL before it touches anything, and the editor is redirected to
the inbox that already renders the card and its preview. No bulk, no second
executor, no special runtime: the expected outcome of pressing the button is a
pause, not a write.

.. _adr-158-consequences:

Consequences
============

**The same seam answers both questions.** :php:`groupsFor()` decides what is
rendered and :php:`runRequestFor()` decides what may start, and the second one
re-asks the first one's question rather than trusting the POST. A request naming
a tool the catalogue never offered produces no run. Split across two services,
that second check is the one a future entry point forgets.

**The catalogue never reads a record.** It is handed a table and a uid and
passes them on; it does not resolve a title, an existence or a permission.
Resolving the record would mean authorising the read, and an unauthorised read
would turn a catalogue into a probe for which uids exist. The record IS resolved
and authorised, twice and later: by :php:`ToolPreviewInterface::previewCall()`
when the run suspends and by :php:`mayViewerReadPreview()` when the card renders
(:ref:`ADR-136 <adr-136>`). The consequence an editor sees is that the page says
``pages #42`` rather than the page's title.

**The grant is ``tasks_use``, not a new one.** The module's existing execution
grant already means "this account may have the extension run a model on its
behalf", and it is checked per action on top of the module switch
(:ref:`ADR-130 <adr-130>`, :ref:`ADR-131 <adr-131>`). A second grant for a
narrower capability inside the same module would be a switch with no distinct
decision behind it — and it would be the weaker of the two protections anyway,
because whether a writing tool may run at all is already an explicit admin act:
every writer ships :php:`isEnabledByDefault() === false`, and a configuration
that restricts tool groups must list ``editing``. ADR-152 deferred a
*per-action* grant to its consumer; the consumer's answer is that the axis it
would add is already covered twice.

**Files have no context-menu entry.** In the file list the context menu's
identifier is a FAL combined identifier (``1:/path/file.jpg``), not a uid, while
``set_file_alternative_text`` declares ``sys_file`` and takes a uid. Casting one
to the other yields a plausible wrong number, so the provider handles integer
identifiers only. The action stays visible in the catalogue and is startable
once a resolution step exists.

**Without a record the catalogue is read-only.** An action needs a subject, and
this module deliberately ships no record picker: a picker is a read boundary
(:ref:`ADR-130 <adr-130>` withheld the ``table`` task input type for exactly
that reason), and building one here would put a second, weaker boundary beside
the one the tools enforce themselves.

.. _adr-158-alternatives:

Alternatives considered
=======================

**A module of its own.** Rejected: same audience, same grant, same inbox as
``nrllm_aitasks``, and :ref:`ADR-119 <adr-119>` already calls the admin tree's
entry count a dumping ground. A second editor-facing module would also need its
own be_groups tick to be reachable at all.

**A bulk surface — one action over many records.** Rejected, and ADR-152 already
said why: the approval unit is a turn, and :ref:`ADR-133 <adr-133>` refuses a
per-call verdict. "Approve 200 writes" is not a decision a card can carry.

**Filtering the catalogue by hand from** :php:`enabledNames()`. Rejected: that
is one of the gate's five checks. It would show an editor actions their
configuration forbids and their trust zone refuses, and the refusal would arrive
as a failed run instead of an absent button.

**Resolving the record title for the catalogue header.** Rejected for now: see
the consequence above. It is a read that must be authorised, and the authorising
code lives in the tools, which cannot be asked without arguments.
