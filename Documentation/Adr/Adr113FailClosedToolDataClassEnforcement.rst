.. include:: /Includes.rst.txt

.. _adr-113:

============================================================================
ADR-113: Fail-closed tool data-class enforcement switch
============================================================================

:Status: Accepted
:Date: 2026-07-23
:Authors: Netresearch DTT GmbH

.. _adr-113-context:

Context
=======

The composite tool gate (:ref:`ADR-094 <adr-094>`) added a trust-zone axis that
denies a tool whose data class exceeds the configuration's trust zone. It is
governed by the ``tools.dataClassEnforcement`` extension setting so operators
can watch it before turning it on: ``observe`` computes and reports the decision
but still offers the tool, any other value enforces.

The switch read **fail-open**: it enforced only on an exact ``enforce`` and
observed on everything else — a typo (``enforced``, ``ENFORCE``), an empty
value, a malformed ``tools`` section, or an extension configuration that threw
on read all silently OBSERVED. A security gate that disables itself on a
misconfiguration is exactly backwards: the operator believes the gate is on
while a mistyped setting leaves it off.

.. _adr-113-decision:

Decision
========

The switch is fail-closed. The axis observes ONLY on a deliberate ``observe``
(matched case- and whitespace-insensitively). Every other case enforces:

- a missing value or ``tools`` section;
- a malformed section (``tools`` is not an array, the value is not a string);
- a typo or any unrecognised value;
- an extension configuration that throws on read.

This cannot over-permit: the four pre-existing gates always enforce, and turning
the trust-zone axis on only ever removes an over-ceiling tool — so failing
closed is strictly safer, never looser. An operator who genuinely wants
observe-only must say so explicitly.

.. _adr-113-consequences:

Consequences
============

- The shipped default is unchanged in this step: ``ext_conf_template.txt`` still
  sets ``tools.dataClassEnforcement = observe``, so a healthy install (fresh or
  upgraded) reads an explicit ``observe`` and behaves exactly as before. Only a
  broken, missing, or mistyped setting changes — from a silent observe to a
  safe enforce.
- Flipping the shipped default to ``enforce`` for new installs (while preserving
  observe for existing ones via an upgrade wizard) and a readiness report are a
  follow-up: they change behaviour for healthy installs and carry a
  backwards-compatibility decision, kept separate from this pure fail-closed fix.
