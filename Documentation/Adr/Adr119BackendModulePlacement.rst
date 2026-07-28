.. include:: /Includes.rst.txt

.. _adr-119:

============================================================================
ADR-119: Where the backend modules live — Administration, for now
============================================================================

:Status: Accepted (deferred — the placement is not finally settled, see Revisit)
:Date: 2026-07-28
:Authors: Netresearch DTT GmbH

.. _adr-119-context:

Context
=======

nr_llm registers a parent module ``nrllm`` with twelve submodules — providers,
models, configurations, tasks, snippets, skills, tools, playground, agent runs,
analytics, setup wizard and overview — under TYPO3's **Administration**
section. The question raised: should this become its own top-level section,
a sibling of Content, Media, Sites, Administration and System, and should it be
called "LLM" or "AI"?

Two arguments were made against a section and both turned out to be worthless,
which is why they are recorded here rather than quietly dropped:

**"They are all admin-only modules, and Administration is the section for
admin-only modules."** Access level is not the grouping principle. In the core,
``site`` holds ``site_configuration`` (``access => admin``) next to
``link-management`` (``access => user``), and ``system`` is
``systemMaintainer`` — stricter than admin, not the same. Sections mix access
levels, so this explains nothing.

**"A move is expensive."** It is — the ``nrllm`` route and its bookmarks, two
``setShortcutContext`` calls, the docheader submodule dropdown, 33 references
across 20 documentation files, roughly 17 backend screenshots, and
``t3_cowriter``'s ``position => ['after' => 'nrllm']`` anchor. But cost answers
"what does it take", not "what is right". The two must not be confused.

.. _adr-119-context-principle:

What the sections actually are
------------------------------

The core's own definitions (``cms-core/Configuration/Backend/Modules.php``)
group by **subject**: content is where writing happens, media where files are
worked with, site where sites are configured, system the installation itself.
Administration is where the instance is administered.

The core also, in v14, added ``integrations`` — ``parent => 'admin'``,
``dependsOnSubmodules``, ``showSubmoduleOverview`` — which is structurally the
same shape as ``nrllm``. That is the core's own answer for a cohesive group of
admin-facing extension modules: a container under Administration, not a new
top-level section.

.. _adr-119-context-editor:

The argument that actually decides it
-------------------------------------

Everything above points at Administration — as long as nr_llm's modules stay a
place where a capability is *configured and inspected* rather than a place
where work is done.

They do not stay that, and the reason is not the editor action API. That API
(see the roadmap) surfaces AI actions inside the consuming extension's UI, on
the record the editor is already working on, so it argues *for* leaving nr_llm
where it is: an editor would never open an nr_llm module.

But a consumer's UI can only ever show data in that consumer's context. An
editor also needs **cross-consumer** answers about themselves:

- what is my budget, and how much of it have I used?
- which tools am I allowed to use?
- which skills apply to me?
- what did I run, across every consuming extension?

and a lead editor needs the same for their editors, or for a group. None of
that is expressible in a consumer's UI, because it is by definition not about
one consumer. It is editorial self-service, and it has no home today: the
analytics module aggregates instance-wide and is admin-only, no per-user
history exists, and per-user budgets have no user-facing view at all.

Once those surfaces exist, nr_llm's module tree is no longer an administration
toolset, and the subject argument flips: AI becomes a place where work happens,
on a par with Content and Media.

.. _adr-119-decision:

Decision
========

**Keep the modules under Administration for now. Do not treat this as settled.**

The cross-consumer editor surfaces do not exist yet, and building a top-level
section for users who cannot yet be served by it would be premature. Nothing
about the current placement blocks them.

.. _adr-119-revisit:

Revisit
=======

Reopen this the moment the first cross-consumer editor surface is planned —
a personal usage-and-budget view, a personal run history, or a lead-editor
view over a group. That is the trigger; not a count of modules, not a
preference about menus.

When it is reopened, these are settled in advance:

- **The section is called "AI", not "LLM".** It would hold tasks, skills,
  tools, agent runs and analytics — not just language models. Integrators look
  for AI.
- **The identifier is vendor-scoped** (``netresearch_ai``), never a bare
  ``ai``. Module identifiers merge last-package-wins, so a generic top-level
  identifier is a shared namespace with no owner: the label and icon would
  depend on package load order, and removing the owning extension would strip
  the routes of any foreign submodules parented to it.
- **Twelve flat entries do not move as they are.** They read as a dumping
  ground at any level. Group them by subject first — setup (provider, model,
  configuration), authoring (tasks, skills, snippets), operation (tools,
  playground, runs, analytics) — and let the section hold three or four
  entries.
- **Old routes keep working.** Keep the submodule identifiers and explicit
  paths, repoint ``setShortcutContext`` from ``nrllm`` to ``nrllm_overview``,
  and give that module ``'aliases' => ['nrllm']`` so existing bookmarks
  resolve — valid only once ``nrllm`` is no longer a registered identifier,
  since an alias is shadowed by a real module of the same name.

.. _adr-119-consequences:

Consequences
============

- No code changes. The placement, identifiers, routes and documentation stay
  as they are.
- The discoverability problem is real and remains: TYPO3's module menu renders
  two levels, and nr_llm's twelve submodules sit at the third, so they are
  invisible from the main menu. That is worth fixing on its own terms — by
  strengthening the Overview as the hub, or by grouping the twelve — and does
  not require the top level.
- If the editor surfaces are built without reopening this ADR, they will land
  in an admin-only section where their users cannot reach them. The revisit
  trigger exists to prevent exactly that.
