.. _adr-168:

========================================================================
ADR-168: A use-case pack declares editor actions, and only declares them
========================================================================

:Status: Accepted
:Date: 2026-08-13
:Extends: :ref:`ADR-163 <adr-163>` (a fourth advisory field on the pack) and
    :ref:`ADR-152 <adr-152>` / :ref:`ADR-158 <adr-158>` (the editor-action
    declarations and the surface that offers them)
:Authors: Netresearch DTT GmbH

Context
=======

A use-case pack installs a configuration preset, tasks and snippets. A task runs
as a plain completion: :php:`TaskExecutionService::execute()` builds a prompt,
injects skills and dispatches it — there is no tool path in it at all. The named
editorial writes an editor actually uses (``set_file_alternative_text``,
``create_translation_draft``, ``update_page_metadata``,
``move_content_element``, ``create_content_element_draft``) are **tools**,
reached through the Editor Action Center, disabled by default and enabled by an
administrator in the Tools module, with every run pausing for approval.

Nothing on :php:`UseCasePack` connected the two. So a pack could claim "installs
these tasks and snippets" and nothing more — which is fine for Editorial
Starter, and useless for a Translation or Media Accessibility pack, whose whole
point is the action. Issue `#769`.

The same file carried a second, smaller defect: :php:`$recommendedToolGroups` is
a :php:`list<string>` that the template printed raw, so ``contnet`` rendered
exactly like ``content``.

Decision
========

**A new advisory field.** :php:`UseCasePack::$recommendedEditorActions` is a
:php:`list<string>` of tool names. It is the fourth field of the same kind as the
governance profile and the tool groups, and it is read by exactly one thing: the
install plan.

**The plan states, it does not act.** :php:`UseCasePackInstaller::plan()` reports
per declared action whether this installation declares it, whether it is enabled,
which record types it addresses, and its tool group — and the plan screen links
to the Tools module and to the Editor Action Center. :php:`install()` never reads
the list. There is no branch that enables a tool group, enables an action or runs
one, and the readout interface has no writing method to call.

The reason is not caution, it is the same decision twice already made.
:ref:`ADR-145 <adr-145>` established that a governance profile describes a
posture and never applies one; :ref:`ADR-140 <adr-140>` refused an automatic
apply engine for the same class of thing. An "apply" button here would be that
engine arriving through the back door, holding the authority of the tool gate —
the one control an administrator sets deliberately on top of the group gate and
the approval pause. A pack is data. It is not allowed to be a privilege.

**One registry, one enabled-check.** The states are read off
:php:`ToolAvailabilityServiceInterface` — the service that already resolves which
tools are registered, which declare an editor action (:ref:`ADR-152 <adr-152>`)
and what is enabled after the group AND tool cascade. No second registry and no
second enabled-check: :php:`EditorActionCatalogue` documents that the enabled
rule already has copies and that a fourth is the one that ages.

**The ADMIN state, not the per-viewer offer.**
:php:`EditorActionCatalogueInterface::groupsFor()` is deliberately *not* what the
plan asks. It answers for one viewer and folds four different reasons — no
default configuration, no access to it, the tool gate, the record type — into a
single absent row. On a plan screen that would make a pack's typo
indistinguishable from a missing default configuration. The plan answers what an
administrator can act on; the catalogue answers what an editor is offered.
Different questions, and the plan needs the one with a remedy attached.

**Core declares the question, the tool module answers it.**
:php:`Service\UseCase` is core, and :ref:`ADR-090 <adr-090>` — enforced by
:php:`ModuleSeamTest` — forbids core importing :php:`Service\Tool`. So
:php:`PackToolReadinessInterface` and its two DTOs live in
:php:`Service\UseCase`, :php:`PackToolReadiness` implements it in
:php:`Service\Tool`, and the container joins them. The alternative was a sixth
name on the seam test's by-name exception list; it was rejected because the
exception list is for classes that *move with* the tool module in a split, and
the pack plan moves with core.

**The constructor validates the new field only.** It rejects a blank entry and a
repeated one in :php:`$recommendedEditorActions`, and leaves
:php:`$recommendedToolGroups` exactly as it was.

The asymmetry is the point. A new field ships with its contract, and no pack
outside this repository declares it yet. :php:`$recommendedToolGroups` is
pre-existing and reached through the frozen ``@api``
:php:`UseCasePackProviderInterface`, so a third-party pack declaring
``['content', 'content']`` is a supported scenario that works today — and
:php:`UseCasePackRegistry` builds every pack in its constructor and catches
nothing, so a new throw there would take down every backend module that injects
the registry. That is the same blast radius this ADR invokes below to keep the
identifier sets open; it cannot be unacceptable for an unknown group and
acceptable for a repeated one.

What the untouched field costs is bounded and visible: a blank entry renders as
an empty badge, a repeated one renders twice. Both are on the plan screen, where
an operator reads them and the pack's author can fix them.

The two rejected alternatives:

* *Validate both and record it as a contract change.* Honest, and still an
  outage for anyone who ships the defect — a backend that no longer starts is
  not a validation message.
* *Validate both and make the registry catch per provider.* A real robustness
  change, and a much larger one: it would make a throwing pack silently absent
  rather than loud, and it would remove the very argument that keeps the
  identifier sets open. If it is wanted it is its own decision, not a side
  effect of adding a field.

**Unknown identifiers are surfaced, not refused at declaration time.** Neither
list is checked against :php:`Domain\Enum\ToolGroup` or against a list of known
editor actions, and this is the point issue `#769` left open:

* Both sets are open. :php:`ToolGroup`'s own docblock says so, and
  :php:`Form\Tca\ToolGroupItems` proves it — the selectable groups are the groups
  of the currently *registered* tools, not the enum's cases. A third-party
  extension ships its own tools, its own group and, through the same ``@api``
  :php:`UseCasePackProviderInterface`, its own pack.
* A throw would not be a validation error, it would be an outage.
  :php:`UseCasePackRegistry` builds every pack in its constructor and catches
  nothing, so one unknown group in one third-party pack would take down every
  backend module that injects the registry.

So the typo is caught where the truth lives: the plan asks the live registry and
renders a third badge, "Not available here", distinct from "Disabled" — and both
cards carry the same explanatory sentence when that badge appears, because the
badge says an identifier is missing while only the sentence says that installing
the pack will not produce it. For the
packs *this repository ships* the check is a test —
:php:`UseCasePackRenderTest::everyEditorActionAndToolGroupTheShippedPacksNameExistsHere`
asserts every recommended group and action of every registered pack exists. That
is closed for our packs and open for everyone else's, which is the split the
extension point requires.

.. _adr-168-not:

What this does not do
=====================

**It does not connect a task to an action.** A pack task still executes as a
completion, and no field here changes that. Wiring a task to perform a write
needs a new execution path with its own fence, preview and audit story; that is a
separate decision and explicitly out of scope.

**It does not block an install.** A declared action that is disabled — or absent
entirely — changes no count on the plan and cannot hide the confirm button. An
advisory declaration that could stop an install would be an execution contract
wearing a different name.

**Editorial Starter declares no action, and that is the truthful answer.** Its
four tasks are text transforms run in the Tasks module; none of them writes a
record. And an editor action runs on the **default** configuration
(:php:`EditorActionCatalogue::usableDefault()`), not on the pack's own, so the
pack's house-style snippet would not reach one. Naming an action there to
demonstrate the field would claim a link the pack's records do not have. The
field earns its keep with the Translation and Media Accessibility packs, which is
what issue `#769` filed it as a precondition for.

Consequences
============

A pack can now state what it is for without overpromising, and an operator sees
on one screen which switch the pack's value still depends on.

:php:`UseCasePackInstaller` gains a constructor argument. It is autowired and
absent from the frozen ``@api`` surface, so the addition breaks no caller the
project supports. It carries no ``@internal`` annotation, which is a gap in the
class rather than a property this record may rely on.

A future ``nr_llm_tools`` extraction has one more seam to satisfy: core would
need a null implementation of :php:`PackToolReadinessInterface` for an
installation without the tool package. Until 1.0 there is one extension and the
container always has the real one.
