.. include:: /Includes.rst.txt

.. _adr-094:

============================================================================
ADR-094: Tool data classes and provider trust zones
============================================================================

:Status: Accepted
:Date: 2026-07-20
:Authors: Netresearch DTT GmbH

.. _adr-094-context:

Context
=======

Tool safety rested on denylists: tables no tool may read, field names that look
like credentials, config files that must not be served. Every audit found
another name nobody had thought of — the extension's own vault-bearing tables,
``sys_file_storage.configuration``, the ``encryptionKey``, connection strings in
a DSN. Six patches on a single day before the 0.23.0 release widened those lists
again. The lists were not wrong; the *shape* was. Enumerating the bad is a race
against every future field name, and the field always moves first.

What was missing is the other half of the question. A denylist asks "is this
name dangerous". It never asks "dangerous **to send where**". A run against a
locally hosted model and a run against a shared external service were offered
the same 38-of-41 default-enabled tools, including environment variables,
phpinfo and exception bodies.

.. _adr-094-decision:

Decision
========

**Every tool declares what kind of data it returns.** ``ToolDataClass`` is a
total order from ``publicContent`` up to ``secretAdjacent``. A tool's class
comes from an explicit declaration or, failing that, from its group's default —
each group taking the class of its worst plausible member. An unknown tool or an
undeclared group resolves to ``secretAdjacent``: fail-closed, because an
unclassified tool is precisely the case the classification exists to catch.

Classes are a property of the **code**, never of configuration. An administrator
must not be able to relabel a tool to widen the gate.

**Every provider declares where it runs.** ``TrustZone`` — ``local``,
``privateHosted``, ``externalEu``, ``externalGlobal`` — is stored on the
provider record and implies a ceiling on the data class a run reaching it may
collect. One monotone comparison, not a 6×4 per-installation matrix: a matrix is
configuration nobody gets right, and an operator who disagrees with a ceiling
can move the provider to a different zone.

The zone is an **operator declaration, not a technical control**. Nothing stops
an administrator labelling an OpenAI provider ``local``; the extension cannot
verify where an endpoint runs. What it buys is that the judgement is made once,
by the person who knows the answer, instead of being implied by whichever tools
happen to be enabled.

**The zone that counts is the worst one reachable.** ``FallbackMiddleware`` hands
the call a *different* configuration when the primary fails, so a local primary
with an external fallback would be offered secret-adjacent tools and then fail
over, carrying the output with it. ``TrustZoneResolver`` therefore takes the
least trusted zone across the configuration and its fallback chain — one level
deep, because fallback is documented as shallow and walking deeper would model a
path that cannot execute.

**One gate decides.** ``ToolCallPolicy`` evaluates all five conditions —
registered, enabled, permitted for the user, within the configuration's groups,
within the zone ceiling — and returns a typed ``ToolPolicyDecision`` rather than
a silent absence, so the reason can be shown. Evaluation is a pure AND; the
order only decides which reason is reported, and it runs cheapest and least
revealing first. A tool that is both disabled and above the ceiling reports the
disablement, so a denial never tells a caller who was already blocked that a
trust-zone axis exists.

**Enforcement ships in observe mode.** ``tools.dataClassEnforcement`` defaults to
``observe``: the decision is computed and logged, the tool is still offered. An
upgrade must not silently strip tools from a working installation. The four
pre-existing gates always enforce; the switch governs only the new axis, so
turning it on can never loosen anything. Anything other than a literal
``enforce`` observes — a typo must not start removing tools from production.

**Existing providers are stamped, not reclassified.** A new provider defaults to
the strictest zone. Applying that retroactively would be an outage in slow
motion, so ``StampProviderTrustZoneUpdateWizard`` writes an explicit zone once,
from the only available signal: an ``ollama`` adapter runs locally, everything
else is external until an operator says otherwise. Afterwards the stored column
is the single source of truth — nothing derives a zone from the adapter type at
runtime, because that would make the declaration optional.

.. _adr-094-consequences:

Consequences
============

- **A database compare is required**, plus running the upgrade wizard.
  ``tx_nrllm_provider.trust_zone`` is new; an un-stamped row resolves to the
  strictest zone, which in enforce mode removes the diagnostics, code and
  configuration groups from runs against that provider.
- The ladder collapses two axes. "How secret" and "where may it go" are not the
  same question — a single scale cannot express "editorial content, EU only".
  An operator who needs them apart moves the provider between zones.
- **Personal data has no case of its own.** The ``accounts`` tools return
  backend users, a GDPR concern rather than a secrecy level. Mapping them to
  ``secretAdjacent`` is conservative, not semantically precise; a proper
  PERSONAL_DATA concept needs its own axis, not a rank on this one.
- One external fallback drags an otherwise local configuration down to the
  external ceiling. That is correct — the run really can reach that provider —
  but it will surprise operators who added a fallback purely for availability.
- ``ToolInterface`` is **not** changed yet. Classifying by group plus seven
  explicit declarations covers all 41 builtins without 41 edits; promoting
  ``getDataClass()`` onto the contract is a later, announced breaking change,
  once observe-mode evidence exists.
- ``isEnabledByDefault()`` and the "never-toggled group is enabled" default stay
  as they are. Flipping them would make a fresh install offer zero tools and buy
  nothing the ceiling does not already buy. The fail-closed default belongs on
  the new axis — a new provider is external until judged — which is where it is
  honest.
- ``ToolCallPolicyInterface`` is published (``public: true``), raising the
  audited public-service count from 33 to **34**. This ADR supersedes ADR-084 as
  the count authority.
