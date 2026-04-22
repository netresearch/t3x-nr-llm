.. include:: /Includes.rst.txt

.. _adr-022:

================================================
ADR-022: Attribute-Based Provider Registration
================================================

:Status: Accepted
:Date: 2026-04
:Authors: Netresearch DTT GmbH

.. _adr-022-context:

Context
=======

Registering a new provider previously required two places to stay in sync:
the class itself, and a ``tags:`` block in :file:`Configuration/Services.yaml`
naming ``nr_llm.provider`` with a numeric priority. Omit either side and the
provider silently vanished from :php:`LlmServiceManager::getProviderList()`.
For the seven shipped providers this is a footgun we kept stepping on during
refactors. For third-party providers it is an onboarding tax.

.. _adr-022-decision:

Decision
========

Introduce :php:`#[AsLlmProvider(priority: N)]` on the provider class and have
:php:`ProviderCompilerPass` scan every container definition at compile time
for the attribute, auto-tagging matched services with ``nr_llm.provider``.

The existing yaml-tagging path still works. When both are present, the yaml
tag wins (the attribute pass skips already-tagged services). This is
deliberate: overrides should be explicit, not silently merged.

The shipped providers now declare their priority via the attribute, and the
``tags:`` entries have been removed from :file:`Configuration/Services.yaml`.
Attribute-tagged providers are also made public automatically by
:php:`ProviderCompilerPass` so that backend diagnostics can resolve them by
class name. The legacy yaml-tagging path still works for third-party
providers, but yaml-tagged services remain private unless the yaml entry
sets ``public: true`` explicitly.

.. _adr-022-tradeoffs:

Trade-offs
==========

* **+ Single source of truth.** The priority lives next to the class, not in
  a sibling yaml file.
* **+ Third-party DX.** External providers drop in without editing yaml:
  :code:`#[AsLlmProvider(priority: 100)]` on an autowired class is enough.
* **+ Backward-compatible.** Existing yaml-tagged providers keep working.
* **- Reflection at compile time.** The compiler pass reflects service
  definitions in the ``Netresearch\NrLlm\`` namespace; other definitions
  are skipped by a prefix match on the class name (no reflection). Cost
  is paid once per container build, cached via
  :php:`ContainerBuilder::getReflectionClass()`, and negligible in
  practice.
* **- Implicit registration.** A new reader grepping ``nr_llm.provider`` in
  yaml no longer finds all providers. Mitigation: the attribute constant
  :php:`AsLlmProvider::TAG_NAME` is discoverable via symbol search.

.. _adr-022-alternatives:

Alternatives considered
=======================

* **Symfony's ``registerAttributeForAutoconfiguration``** — the idiomatic
  path, but TYPO3's DI bootstrap does not expose the underlying container
  builder at a hook point where attribute registration would work cleanly
  for every installed extension. A compiler pass runs at the right
  lifecycle stage and touches only our tag.

* **Keep yaml tags only.** Rejected: the double-bookkeeping problem was the
  whole motivation.

* **Scan providers directory by namespace.** Rejected as too magical —
  implicit "any class ending in Provider" registration is a known anti-pattern.
