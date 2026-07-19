.. include:: /Includes.rst.txt

.. _adr-090:

============================================================================
ADR-090: One extension until 1.0, with documented split seams
============================================================================

:Status: Accepted
:Date: 2026-07-19
:Authors: Netresearch DTT GmbH

.. _adr-090-context:

Context
=======

``nr_llm`` has grown well beyond a provider abstraction. It now bundles several
subsystems that are, in principle, independently useful:

- the **core** three-tier abstraction (Provider → Model → Configuration), the
  middleware pipeline (fallback, budget, usage, cache) and the completion /
  embedding / vision feature services;
- **specialized services** — DeepL / LLM translation, DALL·E / FAL image
  generation, Whisper / TTS speech (``Classes/Specialized/``);
- the **tool / agent system** — the builtin tool set, RAG site-search retrieval,
  the tool loop, human-in-the-loop approval and agent-run persistence
  (``Classes/Service/Tool/``, ADR-042 ff., ADR-084);
- the **guardrail / redaction** safety pipeline (``Classes/Service/Guardrail/``,
  ADR-085 ff.);
- the **backend UI** — Tool Playground, analytics dashboard, setup wizard and
  skills management (``Classes/Controller/Backend/``).

Different consumers want different subsets: an agency embedding the provider
core in its own product does not need the backend dashboards; a site that only
translates does not need the agent stack or its attack surface. That makes a
split into focused extensions (``nr_llm`` core plus optional feature packages)
an attractive long-term shape.

It is, however, the wrong move **now**. The extension is under rapid pre-1.0
development: the internal contracts between these subsystems still change often.
Splitting today would freeze those contracts into public, cross-extension APIs
prematurely, and impose coordinated multi-repo releases and an
extension-version compatibility matrix — friction that would slow exactly the
iteration that is still happening.

.. _adr-090-decision:

Decision
========

**Ship ``nr_llm`` as a single extension until the 1.0 release, and revisit the
split with or before 1.0 — once the internal seams have stabilized.**

Until then, keep the architecture *split-ready* rather than split: preserve
clean module boundaries so a future extraction is a packaging change, not a
re-architecture. The phpat architecture tests (``Tests/Architecture/``) already
enforce the **vertical** layering — Controller → Service → Provider / Domain
(e.g. controllers use the tool registry rather than concrete adapters, services
do not depend on controllers or concrete provider adapters, domain models stay
free of repositories/HTTP). They do **not** yet police the **horizontal** seams
*between* the feature modules (specialized ↔ tools ↔ guardrail ↔ backend); those
are currently kept clean by review. Extending phpat to assert the horizontal
module boundaries is itself part of the split-readiness work, and new
cross-module coupling that would block an extraction is treated as a defect.

Anticipated split seams (candidate extensions):

.. list-table::
   :header-rows: 1
   :widths: 22 20 58

   * - Package
     - Depends on
     - Scope
   * - ``nr_llm`` (core)
     - —
     - Provider → Model → Configuration, middleware pipeline, the completion /
       embedding / vision feature services, ``LlmServiceManager``. The
       mandatory base of every other package.
   * - ``nr_llm_specialized``
     - core
     - Translation (DeepL / LLM), image (DALL·E / FAL), speech (Whisper / TTS),
       and their per-modality provider adapters.
   * - ``nr_llm_tools``
     - core
     - The builtin tool set, RAG site-search retrieval, the tool loop,
       human-in-the-loop approval and agent-run persistence.
   * - ``nr_llm_guardrail``
     - core
     - The guardrail / secret-redaction safety pipeline. May instead remain in
       core, since "secure by default" is a core promise.
   * - ``nr_llm_backend``
     - core (+ installed feature packages)
     - Tool Playground, analytics dashboard, setup wizard, skills management —
       the backend modules and their widgets.

A subsystem is a candidate for extraction only once **all** of the following
hold:

- its contract with core has been stable across several releases (few or no
  breaking changes);
- a concrete consumer benefits from installing it separately (smaller footprint
  or reduced attack surface); and
- the 1.0 public-API freeze is planned or in progress.

.. _adr-090-consequences:

Consequences
============

- **Now:** one repository, one release pipeline, one version — fast iteration.
  The cost is a larger install footprint for consumers who want only a subset,
  and a broader default attack surface (mitigated by the tool availability
  gating and guardrail defaults).
- **Split-ready discipline:** module boundaries must stay clean. The phpat
  architecture tests guard the vertical layering automatically; the horizontal
  seams (core reaching into the backend UI, or one feature module reaching into
  another) are today a review responsibility. Adding phpat rules for the module
  seams — so a wrong-way dependency fails CI rather than only review — is a
  concrete, cheap step toward split-readiness.
- **At 1.0:** re-evaluate against the criteria above. If the seams have held,
  the split is largely a ``composer.json`` / ``ext_emconf.php`` repackaging plus
  moving files along the documented boundaries; if a consumer need is real
  before 1.0, a single package (most likely ``nr_llm_specialized`` or
  ``nr_llm_tools``) can be extracted early without committing to the full split.

.. _adr-090-alternatives:

Alternatives considered
=======================

- **Split now.** Rejected: premature. It would freeze still-churning internal
  contracts as public APIs and add coordinated-release friction during the phase
  where iteration speed matters most.
- **Never split; stay monolithic.** Rejected: the subsystems are genuinely
  separable and real consumers want subsets. Committing to a permanent monolith
  would, over time, invite the cross-module coupling this ADR exists to prevent.
