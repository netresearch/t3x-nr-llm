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
====================

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
===========

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
===============

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
