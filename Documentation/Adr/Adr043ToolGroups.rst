.. include:: /Includes.rst.txt

.. _adr-043:

=========================================================
ADR-043: Tool groups with a fail-closed enable cascade
=========================================================

:Status: Accepted
:Date: 2026-07-08
:Authors: Netresearch DTT GmbH

.. _adr-043-context:

Context
=======

With sixteen built-in tools (:ref:`ADR-038 <adr-038>`,
:ref:`ADR-042 <adr-042>`) and third-party tools registering through the same
``nr_llm.tool`` tag, per-tool administration stops scaling: an admin who
wants "no system introspection for this instance" or "only content tools for
this configuration" has to know and toggle every individual tool — and a
tool added later by an extension update silently escapes a decision that was
meant to cover its whole family.

.. _adr-043-decision:

Decision
========

Every tool declares a **group**, and enablement cascades across three
levels, fail-closed:

``ToolInterface::getGroup(): string`` (**breaking**, third parties must
implement it — the same expansion pattern as ``requiresAdmin()`` in
:ref:`ADR-038 <adr-038>`). Built-ins use the curated taxonomy ``content``,
``structure``, ``system``, ``accounts``, ``configuration``. Third-party
tools declare their own group; the recommended value is the providing
extension's key.

**Level 1 — central group state.** ``tx_nrllm_tool_group_state`` stores
per-group admin overrides (mirroring ``tx_nrllm_tool_state``; a missing row
means *enabled*). Because the state is keyed by group **name**, a disabled
group also covers same-group tools installed later.
``ToolAvailabilityService`` computes the effective state as
``group_enabled && tool_enabled``: a per-tool override can **not**
re-enable a tool inside a disabled group. The alternative — letting an
explicit tool override outrank its group — was rejected because it turns
"disable the group" into a soft hint whose real effect depends on
invisible per-tool rows; the chosen rule keeps one glance at the group
toggle authoritative.

**Level 2 — per configuration.** ``tx_nrllm_configuration`` gains
``allowed_tool_groups`` (comma list via ``selectCheckBox``; items derived
from the registry by an ``itemsProcFunc``, so third-party groups appear
automatically). Empty means "no group restriction".
``AllowedToolsResolver`` intersects the skill-declared ``allowed-tools``
union with the group gate; when only the group gate is set, it becomes the
allow-list itself.

**Level 3 — per run.** The playground groups its tool checkboxes and adds a
group checkbox with an indeterminate mixed state; the per-run selection is
still intersected with levels 1–2 by the runtime gate.

.. _adr-043-consequences:

Consequences
============

- **Breaking**: every ``ToolInterface`` implementation must add
  ``getGroup()``. Costs one method per tool; buys family-wise control that
  survives extension updates.
- An unknown or never-toggled group is **enabled** — grouping restricts, it
  does not quarantine new tools (``isEnabledByDefault()`` and
  ``requiresAdmin()`` keep covering per-tool risk).
- The group table stays name-keyed and FormEngine-free, like the tool-state
  table; orphaned group rows (extension removed) are harmless and inert.
- The configuration gate composes with — never replaces — the global
  cascade: a globally disabled tool stays off even when its group is listed
  in ``allowed_tool_groups``.
