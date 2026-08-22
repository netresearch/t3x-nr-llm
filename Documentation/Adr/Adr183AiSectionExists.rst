.. include:: /Includes.rst.txt

.. _adr-183:

============================================================================
ADR-183: The AI section exists, and editor surfaces live in it
============================================================================

:Status: Accepted
:Date: 2026-08-22
:Amends: :ref:`ADR-119 <adr-119>` (backend module placement)
:Authors: Netresearch DTT GmbH

.. _adr-183-context:

Context
=======

:ref:`ADR-119 <adr-119>` decided *Administration, for now*, and left the
placement open with a named revisit trigger: the first cross-consumer editor
surface. Issue #812 carried that open question so the next surface would not
settle it by accident.

The question was put and answered rather than triggered. The section is
created, and it is called **AI**.

Two arguments were weighed once more before deciding, and both are recorded
because the second overturns a reason ADR-119 gave for the name.

**"LLM" is the more precise name.** It is not. Of the four products that share
the section, ``nr_repurpose`` generates audio and images, ``nr_ai_search``
fuses BM25 keyword search with embeddings, and the cowriter drives CKEditor —
only nr_llm is a language-model product. A section named LLM holding a product
called *AI Search* that runs no language model would be wrong, not merely
unfashionable.

**"Integrators look for AI."** ADR-119 gave this as a reason and it does not
hold: :ref:`ADR-131 <adr-131>` establishes that the section exists for
*editor*-facing modules, so the label is read by editors. The conclusion
survives its reason — "AI" is what an editor recognises, "LLM" is a
developer's word — but the reason itself is withdrawn here.

.. _adr-183-decision:

Decision
========

A shared top-level section ``netresearch_ai`` holds five entries:

.. code-block:: text
   :caption: The registered structure

   netresearch_ai            no access check of its own
   ├── nrllm_aitasks         access => user     (editors)
   ├── nrllm_overview        access => admin    (aliases: nrllm)
   ├── nrllm_setup           providers, models, configurations, use-case, wizard
   ├── nrllm_authoring       tasks, skills, snippets
   └── nrllm_operation       tools, MCP, playground, runs, analytics

Four of those five come from the fourteen entries ADR-119 called a dumping
ground, which is the "three or four entries" it asks for. ``nrllm_aitasks``
is the fifth and was never one of the fourteen: it sat under ``web``.

.. _adr-183-mechanic:

The access check is the whole mechanic
--------------------------------------

The module menu drops every top-level module whose own access check fails,
together with its children (:ref:`ADR-131 <adr-131>`). That is why
``nrllm_aitasks`` was parented to ``web``: under the admin-only ``nrllm``
container it would have been invisible to the editors it exists for. Every
further editor surface would have landed flat under ``web`` for the same
reason, one entry at a time — the accretion #812 was filed to prevent.

A section carries **no** ``access`` key, exactly like the core's own sections
in ``cms-core/Configuration/Backend/Modules.php``. It therefore never filters,
and its children filter individually. Admin-only and editor-facing modules can
share one place for the first time.

The nesting depth is unchanged. ``tools → nrllm → nrllm_providers`` was
already three levels; ``netresearch_ai → nrllm_setup → nrllm_providers`` is
the same shape with the top level owned rather than borrowed.

.. _adr-183-settled:

The four pre-settled answers, as applied
----------------------------------------

ADR-119 settled four things in advance for this moment. Three are applied
verbatim; the fourth is applied with its reason corrected above.

- **The name is "AI"** — applied, reason revised.
- **The identifier is vendor-scoped** (``netresearch_ai``) — applied. Module
  identifiers merge last-package-wins, so a bare ``ai`` would be a shared
  namespace with no owner.
- **The flat entries do not move as they are** — applied. Three subject
  containers, holding five, three and five entries.
- **Old routes keep working** — applied. Submodule identifiers and explicit
  paths are unchanged, ``setShortcutContext`` is repointed from ``nrllm`` to
  ``nrllm_overview``, and that module carries ``'aliases' => ['nrllm']``.

.. _adr-183-consequences:

Consequences
============

**Backend shortcuts survive.** They store the module identifier, and the alias
resolves it. This works only because ``nrllm`` is no longer registered as a
real module — an alias is shadowed by a real module of the same name.

**Foreign anchors survive, and this was verified rather than assumed.**
``t3_cowriter`` positions itself with ``['after' => 'nrllm']``.
``ModuleFactory::adaptAliasMappingFromModuleConfiguration()`` rewrites
``position.before`` and ``position.after`` through aliases, not just
``parent``, so that anchor re-resolves to ``nrllm_overview`` with no change in
the cowriter.

**The container URL does not survive.** ``/module/nrllm`` was the container's
own path and has no successor; ``/module/nrllm/overview`` and every submodule
path are unchanged. ADR-119 accepted this when it settled on keeping the
submodule paths.

**The three sibling extensions are not changed by this record.** They may
register under ``netresearch_ai`` when they choose to; nothing breaks while
they do not. What this decision gives them is a place that exists.

**The revisit trigger is spent.** ADR-119's open question is closed, so a new
editor surface is now an ordinary addition to an existing section rather than
an occasion to decide where the section should be.

.. _adr-183-alternatives:

Alternatives
============

**A container under Administration**, the shape the core uses for
``integrations``. Rejected for the access-check reason above: a container
inherits its parent's visibility, and Administration is admin-only, so the
editor surfaces this exists for would still have had nowhere to live.

**Leaving it until the trigger fired.** Rejected because the trigger fires
*inside* a change that is about something else, which is exactly how a
placement gets settled by accretion. #812 existed to take the decision out of
that moment.
