.. include:: /Includes.rst.txt

.. _adr-159:

============================================================================
ADR-159: One extension, confirmed at the 1.0 API freeze
============================================================================

:Status: Accepted
:Date: 2026-08-11
:Amends: :ref:`ADR-090 <adr-090>` (its scheduled 1.0 re-evaluation)
:Authors: Netresearch DTT GmbH

.. _adr-159-context:

Context
=======

:ref:`ADR-090 <adr-090>` decided to ship one extension until 1.0 and to
revisit the split "with or before the 1.0 release", against three criteria.
The 1.0 API freeze is now in progress — the ``@api`` snapshot records
constructors, its failures are classified additive vs. breaking, the
deprecation policy is written down and half-enforced
(:ref:`api-deprecation`), and the support matrix is pinned against
``composer.json``, ``ext_emconf.php`` and the CI matrix
(:ref:`api-support-matrix`). That triggers the re-evaluation ADR-090 asked
for, so this record answers it rather than deferring again.

Four questions, answered against the code as of 2026-08-11.

.. _adr-159-questions:

Does a consumer need only the provider core?
--------------------------------------------

**Architecturally yes; in evidence, unproven.**

The seam exists and is enforced, not merely documented:
``Tests/Architecture/ModuleSeamTest`` asserts that core depends on neither
the tool/agent module, the specialized services, the guardrail module nor the
backend UI, in either direction where the direction is wrong. Extracting the
core would be a packaging change, as ADR-090 intended.

The cost of *not* splitting is measurable. ``Classes/`` holds 658 PHP files;
the provider core a "only chat and embeddings" consumer uses is the 51 files
under ``Provider/``, the 13 under ``Service/Feature/`` and the middleware
pipeline. ``ext_tables.sql`` declares 24 tables, of which 6 are core
(provider, model, configuration, its backend-group join, user budget, service
usage) and 18 belong to the feature modules a core-only consumer never
touches.

What is missing is a consumer. No downstream package in this organisation has
asked for a subset, and the argument for a split cannot be built out of a
hypothesis about one. ADR-090's second criterion — "a concrete consumer
benefits from installing it separately" — is therefore **not met**.

Are the agent runtime's dependencies heavy?
-------------------------------------------

**No — and this is the answer that most changes the picture.**

The usual reason to extract an agent runtime is that it drags a dependency
tree behind it. It does not here. ``composer.json`` requires seven packages:
``php``, ``netresearch/nr-vault``, ``psr/http-client``, ``psr/http-factory``,
``psr/log``, ``symfony/yaml`` and ``typo3/cms-core``. Not one of them is
owned by the agent runtime, the tool module or MCP; the core needs all seven
by itself. The 37 files under ``Service/Agent/`` and the 107 under
``Service/Tool/`` add zero third-party packages.

The genuinely optional couplings are already soft: ``tpwd/ke_search``,
``typo3/cms-indexed-search`` and Solr are ``require-dev`` only and guarded at
runtime by ``ExtensionManagementUtility::isLoaded()`` in the respective
retrieval backends.

So a split would not shrink anybody's ``vendor/`` directory by a single
package. What it would shrink is installed schema and default attack
surface — real, but addressed today by the tool availability gate
(:ref:`ADR-120 <adr-120>`) and the guardrail defaults, not by packaging.

Can MCP be optional?
--------------------

**It already is, at runtime — which is why extracting it buys little.**

``Classes/Service/Tool/Mcp/`` is 10 files and two tables
(``tx_nrllm_mcp_server``, ``tx_nrllm_mcp_tool``), with a hand-rolled PSR-18
transport and no third-party client library. ``McpToolProvider::tools()``
iterates configured server rows; with no rows it yields nothing, so an
installation that never configures an MCP server runs no MCP code and
exposes no MCP tool.

As a package, MCP is the cleanest seam in the extension — and the least
worthwhile: 10 of 658 files, in exchange for a second repository, a second
release pipeline and a version-compatibility matrix.

Are there real independent release cycles?
------------------------------------------

**No.** Measured over the three most recent minors, by the module
directories each release's ``Classes/`` diff touched:

.. list-table::
   :header-rows: 1
   :widths: 26 74

   * - Release
     - Module directories touched
   * - 0.25.0 → 0.26.0
     - 12+, led by ``Service/Tool`` (80 files), ``Controller/Backend`` (27),
       ``Domain/ValueObject`` (19), and also SetupWizard, Evaluation, Agent,
       Retrieval, Feature, Provider/Middleware, Domain/Model, Domain/Enum,
       Widgets
   * - 0.26.0 → 0.27.0
     - 12, led by ``Service/Tool`` (18), ``Provider/Middleware`` (11),
       ``Service/Agent`` (9), plus SetupWizard, Backend, Telemetry, Health,
       Skill, Governance, Context, Analytics
   * - 0.27.0 → 0.28.0
     - 3 — the one small release in the set

Every substantial release so far spans core, tools, agent and backend at
once. Under a split each of those would have been a coordinated multi-repo
release. There is no cadence to separate because no module has one.

.. _adr-159-decision:

Decision
========

**Stay one extension through 1.0.** ADR-090's timing — "with or before the
1.0 release" — is met by this record, not by a split: the re-evaluation
happened, and its outcome is that the split is still the wrong move.

Of ADR-090's three extraction criteria:

- *the 1.0 public-API freeze is planned or in progress* — **met**, that is
  what this change set is;
- *a concrete consumer benefits from installing it separately* — **not
  met**, no consumer has asked;
- *the contract with core has been stable across several releases* —
  **unproven**, and the instrument that would prove it is one release old.
  The snapshot did not record constructors until now, and adding them
  surfaced 64 previously invisible signature lines. No release has yet
  shipped under a complete frozen surface, so "stable across several
  releases" cannot honestly be claimed for any module.

The next re-evaluation is due at the **first minor after 1.0**, by which
point the completed snapshot will have covered at least one full release
line and the first criterion becomes answerable with evidence rather than
with an impression.

.. _adr-159-consequences:

Consequences
============

- ADR-090 stays in force; its split-seam table remains the plan of record
  and the phpat seam rules remain the thing that keeps it executable. This
  record is an amendment, not a replacement.
- The README's packaging section keeps saying the same thing, including the
  "with or before 1.0" timing, which this record satisfies rather than
  changes.
- The measurable costs of one extension are now written down (18 non-core
  tables, 658 files) so the next re-evaluation starts from numbers rather
  than from the same qualitative argument.
- "Heavy agent dependencies" is retired as a split motivation. Should the
  question return, the argument has to be made on schema footprint or
  attack surface, because the dependency tree does not support it.

.. _adr-159-alternatives:

Alternatives considered
=======================

- **Extract ``nr_llm_tools`` now**, since it is the largest module (107
  files under ``Service/Tool/``, 37 under ``Service/Agent/``). Rejected: it
  is also the module that changed most in every recent release, so it is the
  one whose contract with core is least settled — precisely the case ADR-090
  says not to freeze.
- **Extract MCP as a proof of concept for the split machinery.** Rejected:
  10 files is not enough load to prove anything about a multi-repo release
  flow, and it would cost a real repository and pipeline to learn it.
- **Defer the re-evaluation again to 1.0 itself.** Rejected: ADR-090 says
  "with or before", the freeze is happening now, and an answer of "still one
  extension, here is the evidence" is a valid outcome that closes the
  question instead of carrying it.
