.. include:: /Includes.rst.txt

.. _adr-056:

===========================================================================
ADR-056: Configuration presets — consumer-declared, admin-imported records
===========================================================================

:Status: Accepted
:Date: 2026-07-13
:Authors: Netresearch DTT GmbH

.. note::

   The backend-module UI named below as a follow-up has been implemented
   since: the Configurations backend module lists pending presets with
   their preflight result and imports one via the existing
   ``nrllm_preset_import`` endpoint, and imported records whose
   declaration checksum drifted are flagged with a non-blocking hint
   (see :ref:`administration-configurations-presets`). The
   checksum-driven *update flow* (diff + re-confirm) has also been
   implemented — see :ref:`the amendment below <adr-056-amendment-update-flow>`.

.. _adr-056-context:

Context
=======

Extensions consuming nr_llm (e.g. ``nr_ai_search``) need specific
``LlmConfiguration`` records to exist — a chat configuration with tool
support, an embedding configuration, and so on. Today every consuming
extension documents these records in prose and each admin re-creates them
by hand, guessing at capabilities and parameters. That is error-prone
(typos in identifiers break the consumer's lookup) and opaque (the admin
cannot see what an installed extension still needs).

At the same time, a consuming extension must never dictate the *supply*
side: which provider, which model, or which API key satisfies its needs is
strictly the admin's decision — nr_llm's three-tier architecture
(:ref:`ADR-001 <adr-001>`) and vault-only key storage
(:ref:`ADR-012 <adr-012>`) forbid anything else. The extension only knows
its *requirements*: "I need a model that can chat and call tools, with at
least 8k context."

nr_llm already has both halves of the machinery: DI-tag discovery with a
tagged-iterator registry (``nr_llm.tool`` — ``ToolInterface`` /
``ToolRegistry``, :ref:`ADR-038 <adr-038>`) and criteria-mode
configurations resolved at runtime by ``ModelSelectionService``.

.. _adr-056-decision:

Decision
========

**Consuming extensions declare the configurations they need as presets via
a DI tag; nr_llm lists undeclared-but-not-yet-imported presets as pending;
a backend admin imports one with a single confirmation.**

1. **Declaration via DI tag.** A consumer implements
   ``ConfigurationPresetProviderInterface`` (tag
   ``nr_llm.configuration_preset``, auto-applied by ``AutoconfigureTag``,
   mirroring ``ToolInterface``) and returns ``ConfigurationPreset`` value
   objects. ``ConfigurationPresetRegistry`` collects them through a tagged
   iterator and fails fast on duplicate identifiers.

2. **Presets express requirements, never supply.** A preset carries a
   namespaced identifier (``nr_ai_search.chat``), name, description,
   ``ModelSelectionCriteria`` (at least one capability is mandatory), and
   optional seeds (system prompt, temperature, max tokens, daily budgets,
   allowed tool groups). It can never name a provider, a model, or an API
   key — the type system simply offers no field for them.

3. **Imported records are criteria-mode configurations.** Import creates
   the record with ``model_selection_mode = criteria``, so
   ``ModelSelectionService`` resolves it on every run against whatever
   providers and models the admin has configured. The admin keeps full
   control: the record is a normal ``tx_nrllm_configuration`` row,
   editable and deletable like any other.

4. **Checksum idempotency.** The preset's SHA-256 checksum over a
   canonical JSON encoding of all declared fields is stored in the new
   ``preset_checksum`` column (type ``passthrough``, no form field).
   "Pending" is defined by identifier absence, so an imported record is
   never re-offered or overwritten; the stored checksum makes a *changed*
   declaration in the consumer detectable for a future "update available"
   surface.

5. **Preflight before import.** ``ConfigurationPresetImportService``
   checks the criteria through the very ``ModelSelectionService`` that
   later resolves the record, and reports either the model the criteria
   currently match or the first requirement that eliminates every
   candidate. Import refuses duplicates and unsatisfiable presets.

6. **Endpoints-first v1.** The admin surface are two admin-gated AJAX
   endpoints (``nrllm_preset_list``, ``nrllm_preset_import``; guard per
   :ref:`ADR-037 <adr-037>`). A backend-module UI on top of them is a
   follow-up, not part of this slice.

.. _adr-056-consequences:

Consequences
============

Positive
--------

* A consuming extension's needs become machine-readable and visible;
  the admin imports a correct record with one confirmation instead of
  hand-copying identifiers and criteria out of a README.
* The supply/demand boundary is enforced by construction: presets cannot
  carry providers, models, or keys.
* Imports cannot silently produce dead configurations — the preflight
  answer comes from the same code path the runtime uses.
* Re-imports are impossible by design (identifier presence), and changed
  declarations are detectable (checksum).

Negative
--------

* One more DI-tag discovery surface to maintain.
* v1 has no backend-module UI; admins need the AJAX endpoints (or the
  follow-up module) to see and import pending presets. *(Implemented
  since — see the note above.)*
* The stored checksum only *detects* declaration drift. *(Superseded: the
  diff + re-confirm update flow is now implemented — see the amendment
  below.)*

.. _adr-056-alternatives:

Alternatives considered
=======================

**Auto-create records at extension install time.** Rejected: it bypasses
the admin's confirmation, creates records that may be unsatisfiable (no
matching model yet), and silently mutates the database on ``composer
require``.

**Declaration via YAML/PHP config files instead of a DI tag.** Rejected:
the DI tag reuses the established, tested discovery mechanism
(``nr_llm.tool``), is auto-wired with zero per-extension configuration,
and gives compile-time class references instead of stringly-typed files.

**Fixed-mode presets naming a concrete model.** Rejected outright: it
would invert the three-tier ownership (:ref:`ADR-001 <adr-001>`) and break
on every instance whose admin chose a different provider.

.. _adr-056-amendment-update-flow:

Amendment (2026-07-14): checksum-driven update flow
===================================================

The update flow the original decision deferred is now implemented as a
diff + re-confirm step on top of the existing drift detection, in the same
scope (no schema change, no new ADR).

**Diff is declared-versus-current.** The as-imported declaration is not
reconstructable (only the checksum is stored), so the diff compares the
current declaration against the record's *current* values. That is exactly
the right basis for the admin's decision: it shows the record values an
update would overwrite. ``ConfigurationPresetDiffService`` produces a
``PresetDiff`` of per-field deltas; ``ConfigurationPreset::toCanonicalArray()``
is the single field list both the checksum and the diff read, so the two
can never disagree.

**What an update applies.** Name, description and the model-selection
criteria always follow the declaration; the optional seeds (system prompt,
temperature, max tokens, the three daily budgets, allowed tool groups) are
applied only when the declaration carries a value. A seed the declaration
left ``null`` never resets the record — seeds are initial values, so the
diff shows only fields the declaration has a value for that differ. After
applying, the record's stored checksum is re-stamped to the current
declaration, which clears the drift hint (an update always resolves the
drift, even when the diff was empty because only a seed was removed).

**What an update never touches.** ``is_active``, ``is_default``,
``be_groups`` and the fallback chain are the admin's — the record stays a
normal row (per point 3 above). If the admin switched the record to
``fixed`` model selection, the update is refused (a typed 422): applying the
declared criteria would override the admin's supply-side model choice.
Updating also runs the same preflight as import, so a changed, currently
unsatisfiable criteria set is refused with the missing requirement named.

**Surface.** Two more admin-gated AJAX endpoints (``nrllm_preset_diff``
GET, ``nrllm_preset_update`` POST; guard per :ref:`ADR-037 <adr-037>`).
The Configurations module renders a "Review update" action next to the
drift hint that opens the diff in a re-confirm modal;
``nrllm_preset_list``'s ``drifted`` entries additively gained a
``changedFields`` summary (existing keys unchanged).
