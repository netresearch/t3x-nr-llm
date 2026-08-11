.. include:: /Includes.rst.txt

.. _adr-152:

============================================================================
ADR-152: An editor action is a declaration, not a second executor
============================================================================

:Status: Accepted
:Date: 2026-08-11
:Authors: Netresearch DTT GmbH

.. _adr-152-context:

Context
=======

Five tools write today (:ref:`ADR-135 <adr-135>`, :ref:`ADR-146 <adr-146>`).
Each one is a narrow editorial act on exactly one record, executed through the
``DataHandler`` under the acting backend user, behind an approval pause and a
write fence. As runtime objects they are complete. As things a HUMAN is offered,
they do not exist at all.

Everything a tool declares about itself is written **for the model**. The name
is a wire identifier (``create_translation_draft``). The description is a
paragraph of English prose telling a language model when to call the tool and
what it will refuse. The group is a bare string. None of it is translatable,
and none of it is what you would put in front of an editor. The admin Tools
module proves the point: it renders ``<code>{tool.name}</code>`` and the raw
model-facing description, because that is all there is to render.

So "what is an editor action?" is an open question with two very different
answers, and the answer decides how much of the runtime gets built twice.

.. _adr-152-decision:

Decision
========

   **An editor action is a produced, narrowly-bounded WRITING TOOL. It is
   declared metadata on top of the existing tool contract, and it executes on
   the existing tool / agent-runtime path.**

There is no :php:`EditorActionInterface::execute()` beside
:php:`ToolInterface::execute()`. Two executors would mean two write paths, two
fences and two audit stories — and the second one would be the one nobody
hardened. Everything that makes a write survivable today is arranged around the
tool path: the fence in :php:`AgentRunExecutor::trace()`
(:ref:`ADR-141 <adr-141>`), the effect stamp on the run row
(:ref:`ADR-111 <adr-111>`), the implied approval a declared write carries
(:ref:`ADR-134 <adr-134>`), the preview produced at suspend
(:ref:`ADR-136 <adr-136>`), and the acting-user authorisation each tool performs
itself (:ref:`ADR-083 <adr-083>`). A parallel executor inherits none of that by
construction; it re-implements it, or it goes without.

What is genuinely missing is not execution. It is the second, human-facing half
of the declaration:

:php:`EditorActionInterface` (opt-in)
   Returns one :php:`EditorAction` value object carrying a translatable label
   key, a short human description key **distinct from the model-facing one**, an
   icon identifier registered in ``Configuration/Icons.php``, and the record
   types the action addresses, machine-readably. It is a marker-style optional
   interface exactly like :php:`ToolEffectInterface` and
   :php:`ToolPreviewInterface`: the read-only builtins are untouched, and a tool
   that does not implement it is simply not an editor action.

   One method returning one object, rather than four getters, so the shape can
   grow without every implementor growing a method.

Its consumer ships in the same change
   :php:`ToolAvailabilityService::editorActions()` collects the declarations,
   and the Tools module renders the icon, the translated name, the human
   sentence and the record types — with the wire name demoted to a technical
   detail rather than removed, because that is the string an admin toggles by.
   A tool without a declaration renders exactly as before.

The declaration is collected by its OWN method, not by ``states()``
   :php:`ToolAvailabilityServiceInterface::enabledNames()` is derived from
   :php:`states()`, and the tool-call gate (:php:`ToolCallPolicy::decide()`)
   reads it on **every** decision. Building the declaration there would run
   foreign code — :php:`EditorAction`'s constructor refuses an empty label key
   or an empty ``recordTypes`` — inside the runtime gate, so a third-party tool
   shipping a malformed declaration would abort tool calling for the whole run
   instead of rendering one row badly. :php:`editorActions()` is therefore a
   separate method that only the module calls, and it drops a declaration that
   throws (logging it) rather than propagating: the row keeps its wire name, the
   module keeps rendering, the run is untouched. Three tests pin it — the state
   rows carry no declaration, a tool whose declaration throws is still listed by
   :php:`enabledNames()` and still allowed by :php:`decide()`, and the module
   renders it under its wire name beside a sound declaration.

``recordTypes`` names the SUBJECT, not the written row
   ``set_file_alternative_text`` declares ``sys_file``: that is the uid the call
   names and the record an editor selects. The row it writes is that file's
   ``sys_file_metadata``. A catalogue answers "what can I do with *this*
   record?", and only the subject answers it.
   ``create_translation_draft`` declares both ``pages`` and ``tt_content``,
   because which one it addresses is the caller's choice rather than a property
   of the tool.

   The rule is mechanical, and a test enforces it: every declared table must be
   one that a **required** argument of the tool's own spec can be filled from.
   ``create_content_element_draft`` therefore declares ``pages`` — its only
   required record identifier is ``page`` — even though the row it creates is a
   ``tt_content`` row. Declaring ``tt_content`` would offer the action on an
   element whose page a caller has no way to learn.

   The rule bounds the subject, not every argument. ``move_content_element``
   declares ``tt_content`` for its ``uid`` and still requires a
   ``target_page`` that no subject supplies; its human description says the
   target belongs in the editor's note, and the approval card shows the
   destination the preview resolved.

.. _adr-152-group:

The group becomes an enum, and ``getGroup()`` stays a string
============================================================

A grouping of actions needs something to render, and a group had no name at all
— only an identifier that happens to be an English word.

:php:`ToolGroup` is an **enum**, not a value object, and it is deliberately
**not** the return type of :php:`ToolInterface::getGroup()`.

- The set of GROUPS is open. A third-party tool declares its own group — the
  recommended value is the providing extension's key — and both the
  ``allowed_tool_groups`` item provider and the egress policy already treat an
  unknown group as ordinary. Narrowing ``getGroup()`` to an enum would close a
  set that must stay open, and would break an ``@api`` interface to do it.
- The set of groups THIS REPOSITORY SHIPS is closed, and was written out twice
  with nothing tying the two lists together: once as the per-group egress
  default (:ref:`ADR-094 <adr-094>`) and once in the builtin-group test. An enum
  makes it exhaustive by construction — a case cannot exist without a label —
  and tests now tie the other two lists to it: one fails when a case has no
  egress default, the other when a builtin declares a group that is not a case.
- A value object would be a string wrapper accepting any value. That is exactly
  the openness the bare string already provides, so it would add a type without
  adding a guarantee.

A group outside the enum resolves to ``null`` and the module renders the raw
identifier. A third-party group stays visible and toggleable; it simply has no
translated name.

While in there: :php:`ToolInterface`'s own docblock listed the taxonomy and
**omitted** ``editing`` — the group all five writers use, and the one this
record is about. Fixed, and pointed at the enum.

.. _adr-152-not-built:

What this record deliberately does NOT build
============================================

``bulkCapability``
   Not built. The approval unit is a **turn**: one digest, one verdict for all
   pending calls, and :ref:`ADR-133 <adr-133>` refuses a per-call verdict
   outright. The fence stamps ONE pending effect per run row. A bulk flag today
   would be read by nothing — and :ref:`ADR-146 <adr-146>`'s *Revisit when* is
   explicit that batching needs a different answer to "what did the approver
   agree to", not a bigger version of this one. A declaration nothing reads is
   worse than none: it reads as enforcement and buys false trust.

A caller-facing preview service, and a structured before/after diff
   Not built. Both are real gaps. Today's preview is
   :php:`ToolPreviewInterface::previewCall()` returning free prose, produced
   inside the loop at the moment of suspension, in the run's actor context, and
   re-authorised per viewer before the approval card renders it
   (:ref:`ADR-136 <adr-136>`). A caller-facing service would need a second
   authorisation story, and a structured diff needs a renderer that knows what
   to do with it. Both belong with the UI that needs them, and neither is that
   UI.

A per-action grant
   Not built. :ref:`ADR-130 <adr-130>` admits a grant only together with its
   consumer, and there is no consumer: enablement already cascades through the
   group gate, the per-tool gate and the per-configuration allow-list, and the
   approver gate is :ref:`ADR-133 <adr-133>`'s.

A sixth writer
   The catalogue is complete. :ref:`ADR-146 <adr-146>` set the next review at a
   sixth writer; this record adds none.

.. _adr-152-consequences:

Consequences
============

✓ A writing tool can be named, described, illustrated and placed by record type
without a change to how it runs. The write path, the fence, the approval pause
and the audit are untouched: each of the five writers gained exactly one method
returning a value object — no :php:`execute()`, no guard, no argument, no fence
was touched, and no executor or fence file was edited at all. Beyond the tools,
the runtime files that changed are the collector,
:php:`ToolAvailabilityService`, and the module controller that renders what it
collects. The collector's new method is off the tool-call path:
:php:`enabledNames()` and :php:`ToolCallPolicy::decide()` never construct an
:php:`EditorAction`.

✓ The Tools module stops showing an administrator a wire name and a paragraph
written for a language model where an editorial act was meant.

✓ The curated group taxonomy is enumerated in :php:`ToolGroup`, and a case
cannot exist without a label in both catalogues or without an egress default.
It is not the *only* place the taxonomy is written down: the egress default is
still keyed by string in :php:`ToolDataClassResolver`, and the builtin-group
test still names a group per builtin. Both are now tied to the enum in the
direction that can go wrong — every case has an egress default, and every group
a builtin declares is a case. The reverse is not asserted: an egress default for
a group no case names is inert, and a case without a builtin is what a taxonomy
looks like the day before its tools land.

✕ The declaration is metadata and cannot be enforced. A third-party writing tool
that does not implement the interface is still a write — the runtime's write
axis is :php:`ToolEffect` and nothing about this record changes that. This is
deliberate: making the declaration mandatory would be a breaking change to
:php:`ToolInterface` for a benefit that is presentational — and presentational
it stays, right down to a broken declaration costing its row a decoration and
nothing more.

✕ Three surfaces render a group name and two of them keep rendering the raw
identifier: the ``allowed_tool_groups`` TCA select and the Playground's grouped
tool checkboxes. The select is a FormEngine item list rather than a template and
its labels are stored operator selections; the Playground list is a picker for
one run rather than the administrative catalogue. The Tools module is the
consumer this record ships; both others are a one-line change whenever they are
next touched.

✕ The label keys live in PHP rather than beside a template ``default``, so a
missing key renders as nothing at all. A test resolves every declared key in
both the English and the German catalogue for exactly that reason.

.. _adr-152-revisit:

Revisit when
============

An Editor Action Center exists — a surface that offers these actions on a
selected record rather than merely listing them. That surface is what will
demand the structured before/after diff and, if it ever offers more than one
record at a time, the answer to "what did the approver agree to" that
``bulkCapability`` would need first.

Also revisit if a third party ships a writing tool. The declaration is opt-in
today because five of five writers are ours; the first foreign one is the
evidence for whether "opt-in metadata" or "part of the write contract" is the
right place for it.
