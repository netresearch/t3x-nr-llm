.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _administration-wizards:

==================
AI-powered wizards
==================

The extension includes AI-powered wizards that use
your existing LLM providers to generate
configurations and tasks automatically. This reduces
manual setup to a minimum.

.. _administration-wizards-setup:

Setup wizard
============

The setup wizard guides first-time configuration in
five steps:

1. **Connect** — enter your provider endpoint and
   API key.
2. **Verify** — test the connection.
3. **Models** — fetch and select models from the
   provider catalogue.
4. **Configure** — generate and select configuration proposals
   for the chosen models.
5. **Review & Save** — review the provider, selected models and
   configurations, then save their records.

.. figure:: /Images/backend-setup-wizard.png
   :alt: Five-step setup wizard with progress
       indicator showing Connect, Verify, Models,
       Configure, and Save steps
   :class: with-border with-shadow
   :zoom: lightbox

   The setup wizard walks through provider creation,
   connection testing, model selection, configuration proposals,
   and reviewing and saving the records in five steps.

The setup wizard is the first entry in
:guilabel:`AI > Setup`. While no provider is
configured, the :guilabel:`AI > Overview` page opens
with a notice and a button to it. On TYPO3 v14 the
:guilabel:`AI > Setup` overview shows the same
notice; TYPO3 v13 has no such overview and opens the
last used entry of :guilabel:`AI > Setup`, or the
setup wizard on the first visit.

.. _administration-wizards-config:

Configuration wizard
====================

The configuration wizard generates a complete LLM
configuration using AI. Instead of filling in each
field manually, describe your use case in plain
language and the wizard generates everything.

1. Navigate to :guilabel:`AI > Setup >
   Configurations`.
2. Click :guilabel:`Create with AI`.
3. Describe your use case (e.g., *"summarize blog
   posts in three sentences"*).
4. The wizard generates: identifier, name, system
   prompt, temperature, and all other parameters.
5. Review and click :guilabel:`Save`.

.. figure:: /Images/backend-config-wizard.png
   :alt: Configuration wizard form with a
       plain-language description field and
       generated configuration preview
   :class: with-border with-shadow
   :zoom: lightbox

   The configuration wizard generates all fields
   from a natural-language description.

.. _administration-wizards-task:

Task wizard
===========

The task wizard creates a complete task setup — a
task **and** a dedicated configuration — in one
step.

1. Navigate to :guilabel:`AI > Authoring >
   Tasks`.
2. Click :guilabel:`Create with AI`.
3. Describe the task (e.g., *"extract the five most
   important keywords from an article"*).
4. The wizard generates: a task with prompt template,
   a configuration with system prompt and parameters,
   and a model recommendation.
5. Review and click :guilabel:`Save`.

.. figure:: /Images/backend-task-wizard.png
   :alt: Task wizard form with description field
       and generated task preview
   :class: with-border with-shadow
   :zoom: lightbox

   The task wizard generates a complete task and
   configuration from a description.

.. _administration-wizards-model-discovery:

Model discovery
===============

On the model edit form, use the
:guilabel:`Fetch Models` button to query the
provider API. This auto-populates available models
with their capabilities, context length, and
pricing metadata.

.. _administration-wizards-capability-seed:

What the capability checkboxes are seeded with
----------------------------------------------

Discovery can use provider-reported capabilities, a bundled model catalogue,
family assumptions and operator edits. The provenance matters: a seeded
checkbox is not a successful test of that capability. See
:ref:`administration-models` for how provenance is shown. The following table
summarizes the sources used by discovery:

.. list-table::
   :header-rows: 1

   * - Provider
     - Discovery source
   * - Mistral
     - chat, tools, vision (per-model ``capabilities``)
   * - OpenRouter
     - chat, tools, vision (``supported_parameters``,
       ``architecture.input_modalities``)
   * - Ollama
     - chat, tools, vision, embeddings (``/api/show``,
       Ollama 0.6 and newer)
   * - Gemini
     - chat, streaming, embeddings
       (``supportedGenerationMethods``); vision and
       tools come from the built-in table for known
       model ids
   * - Anthropic
     - chat, vision, tools, streaming for every model
       the listing returns — it returns Claude chat
       models only, and they all have them
   * - OpenAI
     - from the built-in table, keyed by model id; an
       id outside it is seeded from its prefix
       (``dall-e-``, ``tts-``, ``whisper-``) and
       otherwise chat alone
   * - Groq
     - chat only — the listing carries no capability
       field at all

Where the API reports nothing, the record is seeded
with the narrowest true statement rather than a
guess. **Check the capability checkboxes after
discovery** and tick what the model actually does:
the field is yours to edit, and configurations that
select models by criteria match against it.
:guilabel:`Fetch Models` in the model form keeps
those edits where discovery cannot say what a model
lacks: for a Groq model, an Ollama model without a
reported list, and an OpenAI or Gemini chat model
outside the built-in table, it adds what it knows
and leaves the other ticks of a record of the same
kind alone.

.. note::

   Models discovered by an earlier version carry
   capabilities that were partly guessed from the
   model name. An upgrade cannot tell an operator's
   deliberate edit from a stale seed, so it does not
   overwrite either. Running :guilabel:`Fetch Models`
   again replaces a guessed tick only where discovery
   states the list: the built-in tables, Mistral,
   OpenRouter, and Ollama models whose capabilities
   the host reported. For the models named above,
   including Ollama models it did not look up or
   could not read, untick a stale capability by hand.

.. _administration-workflow:

Recommended workflow
====================

For a fresh installation:

1. Run the **Setup wizard** from the dashboard
   to create your first provider, select models
   and save the generated configurations.
2. Use the **Configuration wizard** to create
   additional use-case configurations (one per
   use case in your extensions).
3. Use the **Task wizard** to create reusable
   prompt templates for editors.
4. Share configuration identifiers with your
   extension developers — they reference them
   in code via
   ``$configRepository->findByIdentifier('...')``.

For ongoing maintenance:

- **Add providers** when you need additional
  AI services or separate prod/dev keys.
- **Fetch models** periodically to pick up new
  models from providers.
- **Edit configurations** to tune prompts and
  parameters — changes take effect immediately
  without code deployment.
