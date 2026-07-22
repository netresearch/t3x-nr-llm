.. include:: /Includes.rst.txt

.. _adr-106:

============================================================================
ADR-106: Per-configuration guardrail policies
============================================================================

:Status: Accepted
:Date: 2026-07-22
:Authors: Netresearch DTT GmbH

.. _adr-106-context:

Context
=======

Guardrails (:ref:`ADR-085 <adr-085>` output, :ref:`ADR-087 <adr-087>` input,
:ref:`ADR-088 <adr-088>` streaming) are applied GLOBALLY: every tagged guardrail
runs on every provider response. Different use-cases need different policies — a
public-facing configuration wants the provider content filter, an internal
tooling configuration may not — but there was no way to vary the set per
configuration. The P1 roadmap asks for exactly that, without ever letting a
configuration weaken the security-critical guardrails.

.. _adr-106-decision:

Decision
========

A configuration selects which OPTIONAL guardrails apply; MANDATORY guardrails
(secret redaction) always run and are never selectable.

- **Identity + classification.** A shared ``GuardrailIdentity`` interface (parent
  of both ``GuardrailInterface`` and ``InputGuardrailInterface``) adds
  ``getIdentifier()`` (a stable slug, SHARED across the input and output sides —
  the two secret-redaction classes report the same ``secret-redaction``) and
  ``isMandatory()`` (the per-class authoring signal). An abstract method, not a
  marker, so every implementer makes a PHPStan-checked conscious choice — a
  forgotten marker would default insecure.
- **Identifier-level authority, fail-closed.** ``GuardrailRegistry`` collects
  both tagged iterators and computes mandatory-ness PER IDENTIFIER. Any side
  mandatory ⇒ the identifier is mandatory; a cross-side disagreement (mandatory
  on one side, optional on the other) THROWS at build — a copy-paste error that
  flips one secret-redaction class to optional fails the container rather than
  shipping a one-sided leak. The ``GuardrailPolicyResolver`` reads the
  registry's verdict, never a raw per-instance flag.
- **The filter.** ``GuardrailPolicyResolver::filter()`` drops a guardrail only
  when the configuration has a NON-EMPTY selection AND the identifier is not
  registry-mandatory AND not in the selection. A null configuration or an empty
  selection runs everything (unchanged from before this ADR); a mandatory
  guardrail is kept against ANY selection value (empty, partial, unknown, or
  all-unknown). One filter, applied at all three points: ``GuardrailMiddleware``
  (output), ``InputGuardrailScreener`` (input), ``StreamingDispatcher`` (live
  redaction + end-of-stream audit) — each reading the configuration already in
  scope (``ProviderCallContext`` / the streamed configuration), no re-plumbing.
- **Storage.** A ``allowed_guardrails`` CSV column on ``tx_nrllm_configuration``
  (mirroring ``allowed_tool_groups``) with a ``selectCheckBox`` TCA field whose
  items are DISCOVERED from ``GuardrailRegistry::selectableIdentifiers()``
  (optional-only; mandatory guardrails are never listed). The migration is
  additive, defaulted ``NOT NULL DEFAULT ''`` — existing rows read ``''`` = run
  all, byte-identical to today.

.. _adr-106-fail-closed:

Fail-closed rules
=================

- A degenerate/empty schema of selectable ids never means "run nothing": the
  mandatory floor is kept unconditionally, and an all-unknown selection keeps
  exactly the mandatory set.
- A guardrail identifier is IMMUTABLE API — a rename silently drops the
  guardrail for configurations that stored the old value (the mandatory floor is
  unaffected; opted-in optional protections would silently stop). Introduce a
  new guardrail instead; a genuine rename requires an Install Tool
  ``UpgradeWizard`` that rewrites stored ``allowed_guardrails`` CSVs.

.. _adr-106-consequences:

Consequences
============

- **BREAKING API change.** ``GuardrailInterface`` / ``InputGuardrailInterface``
  are documented public extension points (the ``nr_llm.guardrail`` /
  ``nr_llm.input_guardrail`` tags). Adding ``getIdentifier()`` + ``isMandatory()``
  fatals an out-of-tree implementer at container compile. See *Upgrading*.
- ``GuardrailRegistry`` is public (Category E, for the TCA itemsProcFunc),
  raising the audited public-service count 35 → **36** (Adr101 remains the count
  authority and is updated in the same change).
- **Content filter is optional.** ``provider-content-filter`` enforces the
  provider's own policy block (``finishReason=content_filter`` → DENY), not
  secret leakage, so a configuration may select it out; secret redaction stays
  mandatory. Flip its ``isMandatory()`` to ``true`` if a deployment treats
  suppressing a provider safety block as a security concern — the registry then
  makes the identifier mandatory and drops it from the picker automatically.
- **Input axis is inert today.** The only input guardrail is the mandatory
  secret redaction, so per-config input filtering changes nothing yet. The
  ``InputGuardrailScreener`` accepts the configuration and applies the same
  filter; ``LlmServiceManager`` passes no configuration for now. When an
  OPTIONAL input guardrail is added, thread the configuration at the
  config-bound entrypoints (a localised follow-up).
- **Eval unaffected.** ``allowed_guardrails`` is a new column defaulting ``''``,
  so every existing configuration (including eval targets) runs all guardrails
  exactly as before.

.. _adr-106-upgrading:

Upgrading
=========

A third-party guardrail implementing ``GuardrailInterface`` or
``InputGuardrailInterface`` must add ``getIdentifier()`` (a stable kebab-case
slug) and ``isMandatory()`` (``true`` only for a security-critical always-on
guardrail). Input and output classes sharing a concept MUST return the same
identifier and the same ``isMandatory()`` value, or the container fails closed.
