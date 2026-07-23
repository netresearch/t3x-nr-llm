.. include:: /Includes.rst.txt

.. _adr-115:

============================================================================
ADR-115: Tool data-class enforcement is the default for new installs
============================================================================

:Status: Accepted
:Date: 2026-07-23
:Authors: Netresearch DTT GmbH

.. _adr-115-context:

Context
=======

The trust-zone tool gate (:ref:`ADR-094 <adr-094>`) shipped in ``observe`` mode:
it computed and logged the decision but still offered an over-ceiling tool, so an
operator could watch it before turning it on. :ref:`ADR-113 <adr-113>` made the
switch fail-closed — only an explicit ``observe`` observes — but the shipped
DEFAULT was still ``observe``, so a brand-new install offered over-ceiling tools
until someone opted in to enforcement. A security control that is off by default
on a fresh install is the wrong default.

The reason it shipped observe-by-default was to avoid an upgrade silently
stripping tools from a working setup. That constraint is real, but it applies to
EXISTING installs, not new ones.

.. _adr-115-decision:

Decision
========

Ship ``enforce`` as the default and preserve ``observe`` for existing installs
with an upgrade wizard, so the two cases are treated differently:

- **New install** — ``ext_conf_template.txt`` now sets
  ``tools.dataClassEnforcement = enforce``. Combined with the fail-closed read
  (:ref:`ADR-113 <adr-113>`), a fresh install enforces without any operator
  action, and a missing or mistyped value also enforces.
- **Existing install** — :php:`DataClassEnforcementDefaultUpdateWizard` pins an
  explicit ``observe`` so the flip changes nothing for a setup that relied on the
  old default, and its description points the operator at the run log and the
  switch to enforce when ready.

Distinguishing the two
----------------------

The wizard fires only when a **provider is configured** (you cannot run a tool
without one, so a fresh install has none) AND the enforcement mode was **never
explicitly stored** — read from the raw
``$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm']`` rather than the
template-merged :php:`ExtensionConfiguration::get()`, which cannot tell "relying
on the default" apart from a deliberate choice. An operator who already chose a
mode is left untouched: an explicit ``enforce`` is respected, an explicit
``observe`` already matches.

.. _adr-115-consequences:

Consequences
============

- A new install is safe by default; the tool gate no longer waits for an opt-in.
- An existing install's behaviour is unchanged across the upgrade — the wizard
  makes its implicit ``observe`` explicit before the default flips under it.
- The change is announced in the extension configuration label and the wizard
  description; combined with the fail-closed read, an operator cannot end up in
  ``observe`` by accident, only by deliberate choice.
- A dedicated readiness report (how many tools each configuration would lose
  under enforce) remains future work; the run log already records what
  enforcement would do while an install is in observe.
