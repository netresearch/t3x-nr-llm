.. include:: /Includes.rst.txt

.. _administration:

==============
Administration
==============

This guide walks you through managing AI providers,
models, configurations, and tasks in the TYPO3
backend. It also covers the AI-powered wizards that
automate most of the setup.

.. contents::
   :local:
   :depth: 2

.. _administration-backend-module:

The LLM backend module
======================

All AI management happens in
:guilabel:`Admin Tools > LLM`. The dashboard shows
your current setup status, quick links to each
section, and AI wizard buttons.

.. figure:: /Images/backend-dashboard.png
   :alt: LLM backend module dashboard showing
       provider count, model count, configuration
       count, and AI wizard buttons
   :class: with-border with-shadow
   :zoom: lightbox

   The LLM dashboard with setup progress, wizard
   buttons, and quick-reference PHP snippets.

The module has five sections accessible from the
left-hand navigation:

- **Dashboard** — overview and wizards
- **Providers** — API connections
- **Models** — available LLM models
- **Configurations** — use-case presets
- **Tasks** — one-shot prompt templates

.. _administration-providers:

Managing providers
==================

Providers represent connections to AI services.
Each provider stores an API endpoint, encrypted
credentials, and adapter-specific settings.

.. figure:: /Images/backend-providers.png
   :alt: Provider list showing adapter type,
       endpoint URL, API key status, and actions
   :class: with-border with-shadow
   :zoom: lightbox

   The provider list with connection status
   indicators and action buttons.

.. _administration-providers-add:

Adding a provider
-----------------

1. Navigate to :guilabel:`Admin Tools > LLM >
   Providers`.
2. Click :guilabel:`Add Provider`.
3. Fill in the required fields:

   :guilabel:`Identifier`
      A unique slug for programmatic access
      (e.g., ``openai-prod``, ``ollama-local``).

   :guilabel:`Name`
      A display name for the backend
      (e.g., ``OpenAI Production``).

   :guilabel:`Adapter Type`
      Select the provider protocol. Available
      adapters: ``openai``, ``anthropic``,
      ``gemini``, ``ollama``, ``openrouter``,
      ``mistral``, ``groq``, ``azure_openai``,
      ``custom``.

   :guilabel:`API Key`
      Your API key. It is encrypted at rest using
      ``sodium_crypto_secretbox``. Leave empty for
      local providers like Ollama.

4. Optionally set the endpoint URL, organization
   ID, timeout, and retry count.
5. Click :guilabel:`Save`.

.. tip::

   Use the :ref:`Setup wizard
   <administration-wizards-setup>` for guided
   first-time setup — it auto-detects the provider
   type from your endpoint URL.

.. _administration-providers-test:

Testing a connection
--------------------

After saving a provider, click
:guilabel:`Test Connection` to verify the setup.
The test makes an HTTP request to the provider API
and reports:

- Connection status (success or failure).
- Available models (if the provider supports
  listing).
- Error details on failure.

.. _administration-providers-edit:

Editing and deleting providers
------------------------------

- Click a provider row to edit its settings.
- Use the :guilabel:`Delete` action to remove a
  provider. Models linked to a deleted provider
  become inactive.

.. _administration-models:

Managing models
===============

Models represent specific LLM models available
through a provider (e.g., ``gpt-5``,
``claude-sonnet-4-6``, ``llama-3``).

.. figure:: /Images/backend-models.png
   :alt: Model list showing capabilities, context
       length, pricing, and default status
   :class: with-border with-shadow
   :zoom: lightbox

   The model list with capability badges, context
   length, and cost-per-token columns.

.. _administration-models-add:

Adding a model manually
-----------------------

1. Navigate to :guilabel:`Admin Tools > LLM >
   Models`.
2. Click :guilabel:`Add Model`.
3. Fill in the required fields:

   :guilabel:`Identifier`
      Unique slug (e.g., ``gpt-5``,
      ``claude-sonnet``).

   :guilabel:`Name`
      Display name (e.g., ``GPT-5 (128K)``).

   :guilabel:`Provider`
      Select the parent provider.

   :guilabel:`Model ID`
      The API model identifier as the provider
      expects it (e.g., ``gpt-5.3-instant``,
      ``claude-sonnet-4-6``).

4. Optionally set capabilities (``chat``,
   ``completion``, ``embeddings``, ``vision``,
   ``streaming``, ``tools``), context length,
   max output tokens, and pricing.
5. Click :guilabel:`Save`.

.. _administration-models-fetch:

Fetching models from a provider
-------------------------------

Instead of adding models manually, use the
:guilabel:`Fetch Models` action to query the
provider API and auto-populate the model list:

1. Ensure the provider is saved and the connection
   test passes.
2. On the model list or model edit form, click
   :guilabel:`Fetch Models`.
3. The extension queries the provider API and
   creates model records with capabilities and
   metadata pre-filled.

This is the recommended approach — it ensures model
IDs match the provider exactly and keeps your
catalogue current as providers release new models.

.. _administration-configurations:

Managing configurations
=======================

Configurations define use-case-specific presets that
combine a model with a system prompt and generation
parameters. Extension developers reference
configurations by identifier in their code.

.. figure:: /Images/backend-configurations.png
   :alt: Configuration list with model assignment,
       use-case type, and parameter summary
   :class: with-border with-shadow
   :zoom: lightbox

   The configuration list showing each entry's
   linked model, use-case type, and key parameters.

.. _administration-configurations-add:

Adding a configuration manually
-------------------------------

1. Navigate to :guilabel:`Admin Tools > LLM >
   Configurations`.
2. Click :guilabel:`Add Configuration`.
3. Fill in the required fields:

   :guilabel:`Identifier`
      Unique slug for programmatic access
      (e.g., ``blog-summarizer``).

   :guilabel:`Name`
      Display name (e.g., ``Blog Post Summarizer``).

   :guilabel:`Model`
      Select the model to use.

   :guilabel:`System Prompt`
      The system message that sets the AI's behavior
      and context.

4. Optionally adjust temperature (0.0–2.0), top_p,
   frequency/presence penalty, max tokens, and
   use-case type (``chat``, ``completion``,
   ``embedding``, ``translation``).
5. Click :guilabel:`Save`.

.. tip::

   Use the :ref:`Configuration wizard
   <administration-wizards-config>` to generate all
   fields from a plain-language description of your
   use case.

.. _administration-configurations-edit:

Editing configurations
----------------------

Click a configuration row to edit. Changes take
effect immediately for any extension code that
references this configuration's identifier — no
code deployment needed.

.. _administration-tasks:

Managing tasks
==============

Tasks are one-shot prompt templates that combine a
configuration with a specific user prompt. They
provide reusable AI operations that editors or
extensions can execute with a single call.

.. figure:: /Images/backend-tasks.png
   :alt: Task list showing task name, linked
       configuration, description, and actions
   :class: with-border with-shadow
   :zoom: lightbox

   The task list with each task's assigned
   configuration and action buttons.

.. _administration-tasks-add:

Adding a task manually
----------------------

1. Navigate to :guilabel:`Admin Tools > LLM >
   Tasks`.
2. Click :guilabel:`Add Task`.
3. Fill in the required fields:

   :guilabel:`Name`
      Display name (e.g., ``Summarize Article``).

   :guilabel:`Configuration`
      Select the LLM configuration to use.

   :guilabel:`User Prompt`
      The prompt template. Use ``{placeholders}``
      for dynamic values.

4. Add a description so other admins understand
   what the task does.
5. Click :guilabel:`Save`.

Example tasks:

- **Summarize content** — condense long articles.
- **Generate meta descriptions** — SEO optimization.
- **Translate text** — one-click translation.
- **Extract keywords** — pull key terms from content.

.. tip::

   Use the :ref:`Task wizard
   <administration-wizards-task>` to generate
   a complete task (including a new configuration)
   from a plain-language description.

.. _administration-wizards:

AI-powered wizards
==================

The extension includes AI-powered wizards that use
your existing LLM providers to generate
configurations and tasks automatically. This reduces
manual setup to a minimum.

.. _administration-wizards-setup:

Setup wizard
------------

The setup wizard guides first-time configuration in
five steps:

1. **Connect** — enter your provider endpoint and
   API key.
2. **Verify** — test the connection.
3. **Models** — fetch available models from the
   provider API.
4. **Configure** — create an initial configuration
   with system prompt and parameters.
5. **Save** — run a test prompt to confirm
   everything works.

.. figure:: /Images/backend-setup-wizard.png
   :alt: Five-step setup wizard with progress
       indicator showing Connect, Verify, Models,
       Configure, and Save steps
   :class: with-border with-shadow
   :zoom: lightbox

   The setup wizard walks through provider creation,
   connection testing, model fetching, configuration,
   and a test prompt in five steps.

Access it from the :guilabel:`Dashboard` when no
providers are configured, or via the setup wizard
link at any time.

.. _administration-wizards-config:

Configuration wizard
--------------------

The configuration wizard generates a complete LLM
configuration using AI. Instead of filling in each
field manually, describe your use case in plain
language and the wizard generates everything.

1. Navigate to :guilabel:`Admin Tools > LLM >
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
-----------

The task wizard creates a complete task setup — a
task **and** a dedicated configuration — in one
step.

1. Navigate to :guilabel:`Admin Tools > LLM >
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
---------------

On the model edit form, use the
:guilabel:`Fetch Models` button to query the
provider API. This auto-populates available models
with their capabilities, context length, and
pricing metadata.

.. _administration-workflow:

Recommended workflow
====================

For a fresh installation:

1. Run the **Setup wizard** from the dashboard
   to create your first provider, fetch models,
   and test a configuration.
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
