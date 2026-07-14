.. include:: /Includes.rst.txt

.. _developer-configuration-presets:

=====================
Configuration presets
=====================

A consuming extension can declare the ``LlmConfiguration`` records it
needs as **configuration presets**. nr_llm lists declared-but-not-yet
imported presets as *pending*; a backend admin imports one with a single
confirmation. See :ref:`ADR-056 <adr-056>` for the design rationale.

A preset expresses **requirements** — model capabilities and constraints
as :php:`ModelSelectionCriteria` — never a concrete provider, model, or
API key. The imported record runs in criteria selection mode, so
:php:`ModelSelectionService` resolves the actual model on every run
against whatever the admin has configured.

.. _developer-configuration-presets-declaring:

Declaring presets in your extension
===================================

Implement :php:`ConfigurationPresetProviderInterface`. The
``nr_llm.configuration_preset`` DI tag is applied automatically when your
extension's :file:`Services.yaml` has ``autoconfigure: true`` (the TYPO3
default):

.. code-block:: php

    <?php

    declare(strict_types=1);

    namespace Vendor\NrAiSearch\Integration;

    use Netresearch\NrLlm\Domain\DTO\ModelSelectionCriteria;
    use Netresearch\NrLlm\Service\Preset\ConfigurationPreset;
    use Netresearch\NrLlm\Service\Preset\ConfigurationPresetProviderInterface;

    final class AiSearchPresetProvider implements ConfigurationPresetProviderInterface
    {
        public function getPresets(): array
        {
            return [
                new ConfigurationPreset(
                    identifier: 'nr_ai_search.chat',
                    name: 'AI Search Chat',
                    description: 'Answers site-search questions with tool support.',
                    criteria: new ModelSelectionCriteria(
                        capabilities: ['chat', 'tools'],
                        minContextLength: 8000,
                    ),
                    systemPrompt: 'You answer questions about this website.',
                    temperature: 0.2,
                    maxTokens: 2000,
                ),
                new ConfigurationPreset(
                    identifier: 'nr_ai_search.embedding',
                    name: 'AI Search Embeddings',
                    description: 'Creates embeddings for the search index.',
                    criteria: new ModelSelectionCriteria(
                        capabilities: ['embedding'],
                        preferLowestCost: true,
                    ),
                ),
            ];
        }
    }

Rules the value object enforces at construction time:

* The identifier is lowercase ``[a-z0-9_]`` segments separated by dots
  and must be namespaced with your extension key
  (``nr_ai_search.chat``) so presets from different extensions cannot
  collide. Duplicate identifiers across providers fail fast at container
  build time.
* The criteria must require at least one capability.

All other fields (system prompt, temperature, max tokens, the daily
budget ceilings ``maxRequestsPerDay`` / ``maxTokensPerDay`` /
``maxCostPerDay``, ``allowedToolGroups``) are optional seeds; ``null``
keeps the column default of the created record.

At runtime, resolve the imported configuration by its identifier as
usual, for example through
:php:`LlmConfigurationServiceInterface`.

.. _developer-configuration-presets-import:

Import flow
===========

#. The admin queries the pending presets (AJAX route
   ``nrllm_preset_list``). Each entry carries a **preflight** result:
   whether the criteria currently match an active model
   (``satisfiable`` + ``matchedModelLabel``), or which requirement
   eliminates every candidate (``missingRequirement``).
#. The admin confirms one import (AJAX route ``nrllm_preset_import``
   with the preset ``identifier``). nr_llm creates an active,
   criteria-mode ``tx_nrllm_configuration`` record and stores the
   preset's checksum in ``preset_checksum``.
#. The record is a normal configuration from then on — the admin can
   edit or delete it. A preset whose identifier already has a record is
   never offered again (and an import attempt is refused), so imports
   are idempotent; the stored checksum makes a changed declaration
   detectable.

Both endpoints are restricted to backend administrators
(:ref:`ADR-037 <adr-037>`). Admins normally go through the
Configurations backend module, which renders the pending presets —
including each preflight result — above the configuration records and
imports one through ``nrllm_preset_import`` with a single click; see
:ref:`administration-configurations-presets`.

.. _developer-configuration-presets-drift:

Change detection
================

``nrllm_preset_list`` additionally returns a ``drifted`` list: imported
presets whose current declaration checksum no longer matches the
``preset_checksum`` stored at import time. Each entry carries
``identifier``, ``name``, ``configurationUid``, and ``changedFields`` — the
machine names of the fields an update would overwrite (an additive summary;
may be empty when the declaration only dropped an optional seed). The
Configurations module flags such records with a non-blocking "Preset
changed" hint next to a :guilabel:`Review update` action.

nr_llm never updates an imported record automatically, but the admin can
review and apply a changed declaration.

.. _developer-configuration-presets-update:

Update flow
===========

Two further admin-gated AJAX endpoints resolve drift:

#. ``nrllm_preset_diff`` (GET, ``identifier``) returns the field-level
   ``changes`` an update would apply — each a ``field`` (machine name, e.g.
   ``temperature`` or ``criteria.capabilities``), the record's ``current``
   value, and the ``declared`` value. It refuses (422) when there is nothing
   to update: the record is up to date, was not imported from a preset, was
   switched to ``fixed`` model selection, or the changed criteria are
   currently unsatisfiable.
#. ``nrllm_preset_update`` (POST, ``identifier``) applies a reviewed update
   after the admin re-confirmed, then returns the ``changedFields`` that were
   applied.

An update follows the declaration for name, description and criteria, and
for each optional seed that carries a value; a seed the declaration left
``null`` does not reset the record. It leaves the admin-owned fields
untouched — active state, default flag, backend groups, and the fallback
chain — and re-stamps the stored checksum so the drift hint clears. See
:ref:`ADR-056 <adr-056>` for the design.

When your extension changes a preset declaration, ship the change; admins
see the drift hint and re-confirm the diff. Document only anything the flow
cannot carry (for example a change that also needs a new provider or model
configured first).
