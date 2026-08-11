.. include:: /Includes.rst.txt

.. _api-deprecation:

==============================
Deprecation and removal policy
==============================

:ref:`ADR-127 <adr-127>` says which classes the semver promise covers.
This page says how something leaves that surface again.

The rule from 1.0
=================

Nothing marked ``@api`` is removed, renamed or narrowed without a
deprecation first:

1. The member ships as ``@deprecated`` in a **released minor** (1.N.0).
2. It keeps working, unchanged, through at least **one further minor line**
   — deprecated in 1.N.0 means still present and still working in 1.N+1.0.
3. Only then may it go, and only in the **next major** (2.0.0). A minor
   release never removes ``@api``.

"Narrowed" includes a widened constructor: a new required argument on a
class consumers build with ``new`` breaks them exactly as a deleted method
would. The API snapshot records constructors for that reason.

During 0.x
==========

The notice period starts at 1.0. While the extension is pre-1.0, minor
releases may break with a CHANGELOG entry under a BREAKING heading — see
:ref:`api-stability`. Deprecations shipped during 0.x are listed below and
are the first candidates for removal at 1.0; the ones marked *retained*
are not, and say why.

What a deprecation must carry
=============================

- ``@deprecated since X.Y.0 — <what to call instead>`` in the member's
  docblock. The ``since`` version is the evidence for the notice period.
- A ``### Deprecated`` entry in ``CHANGELOG.md`` naming the replacement.
- A row in the inventory below.

What enforces what
==================

Half of this policy is a gate and half is a review duty. The difference
matters, so it is written down rather than implied.

.. list-table::
   :header-rows: 1
   :widths: 44 40 16

   * - Rule
     - Enforced by
     - Mechanical
   * - A removed ``@api`` member cannot land unnoticed
     - ``Tests/Unit/Api/ApiSurfaceSnapshotTest`` — the rendered surface is
       frozen in ``api-surface.txt`` and a removal is classified
       ``breaking``, which forces an explicit snapshot change in the same
       pull request
     - yes
   * - A changed signature — including a widened constructor — cannot land
       unnoticed
     - the same test; constructors are part of the rendered surface
     - yes
   * - Every ``@deprecated`` member of an ``@api`` class has a written
       migration
     - ``Tests/Unit/Api/DeprecationInventoryTest`` — the member needs a row
       between the inventory markers *and* a non-empty "Use instead" cell.
       It reads the docblocks for all five shapes a deprecation takes:
       method, constant, public property, enum case and the type itself
     - yes
   * - The inventory cannot keep listing what the code no longer deprecates
     - the same test, in the other direction
     - yes
   * - The notice period itself — deprecated for at least one minor line
       before removal
     - review. Nothing in the repository knows in which release a docblock
       tag first appeared; the ``since`` version is the only evidence, and
       a reviewer has to read it
     - **no**
   * - The ``### Deprecated`` CHANGELOG entry
     - review. ``Build/Scripts/check-changelog-unreleased.php`` refuses a
       ``[Unreleased]`` section that repeats itself; it does not require any
       particular entry to be present
     - **no**

Currently deprecated
====================

Everything ``@deprecated`` on an ``@api`` class today, with the call that
replaces it. The two directions of this table are asserted against the
docblocks, so it is complete by construction.

.. deprecation-inventory-start

.. list-table::
   :header-rows: 1
   :widths: 34 12 54

   * - Member
     - Since
     - Use instead
   * - ``Model::getCapabilities()``
     - 0.8.0
     - ``getCapabilitySet()``
   * - ``Model::getCapabilitiesArray()``
     - 0.8.0
     - ``getCapabilitySet()->toStringList()``. The typed list deduplicates
       and drops unknown tokens; the legacy accessor preserves both.
   * - ``Model::getCapabilitiesAsEnums()``
     - 0.8.0
     - ``getCapabilitySet()->capabilities``
   * - ``Model::setCapabilities()``
     - 0.8.0
     - ``setCapabilitySet()`` with a typed set — it validates against the
       capability enum and deduplicates.
   * - ``Model::setCapabilitiesArray()``
     - 0.8.0
     - ``setCapabilitySet(CapabilitySet::fromArray(...))``
   * - ``Model::hasCapability()``
     - 0.8.0
     - ``getCapabilitySet()->has()`` — accepts both the enum and the legacy
       string form.
   * - ``Model::addCapability()``
     - 0.8.0
     - ``setCapabilitySet(getCapabilitySet()->with(...))``
   * - ``Model::removeCapability()``
     - 0.8.0
     - ``setCapabilitySet(getCapabilitySet()->without(...))``
   * - ``Provider::getOptions()``
     - 0.8.0
     - ``getOptionsObject()``. *Retained* — Extbase hydrates the entity
       through this getter/setter pair.
   * - ``Provider::getOptionsArray()``
     - 0.8.0
     - ``getOptionsObject()``
   * - ``Provider::setOptions()``
     - 0.8.0
     - ``setOptionsObject()``. *Retained* — Extbase property mapping.
   * - ``Provider::setOptionsArray()``
     - 0.8.0
     - ``setOptionsObject()``
   * - ``LlmConfiguration::SELECTION_MODE_FIXED``
     - —
     - the ``ModelSelectionMode`` enum, case ``FIXED``
   * - ``LlmConfiguration::SELECTION_MODE_CRITERIA``
     - —
     - the ``ModelSelectionMode`` enum, case ``CRITERIA``
   * - ``LlmConfiguration::getModelSelectionCriteria()``
     - 0.8.0
     - ``getModelSelectionCriteriaDTO()``. *Retained* — Extbase property
       mapping.
   * - ``LlmConfiguration::setModelSelectionCriteria()``
     - 0.8.0
     - ``setModelSelectionCriteriaDTO()``. *Retained* — Extbase property
       mapping.
   * - ``LlmConfiguration::getOptions()``
     - 0.8.0
     - ``getOptionsArray()``. The ``options`` field carries provider-specific
       extras, so the typed surface stops at the array. *Retained* — Extbase
       property mapping.
   * - ``LlmConfiguration::setOptions()``
     - 0.8.0
     - ``setOptionsArray()``. *Retained* — Extbase property mapping.
   * - ``LlmConfiguration::getFallbackChain()``
     - 0.8.0
     - ``getFallbackChainDTO()``. *Retained* — Extbase property mapping.
   * - ``LlmConfiguration::setFallbackChain()``
     - 0.8.0
     - ``setFallbackChainDTO()``. *Retained* — Extbase property mapping.

.. deprecation-inventory-end

*Retained* means the member is deprecated for application code but cannot be
deleted: Extbase hydrates the entity through the raw getter/setter pair, so
removing it would break persistence rather than only callers. Those rows
stay past 1.0 and past 2.0. The two ``SELECTION_MODE_*`` constants predate
the ``since`` convention; they carry no version because none was recorded,
not because none applies.
