.. include:: /Includes.rst.txt

.. _administration:

==============
Administration
==============

This guide walks you through managing AI providers,
models, configurations, and tasks in the TYPO3
backend. It also covers the AI-powered wizards that
automate most of the setup.

.. _administration-backend-module:

The LLM backend module
======================

All AI management happens in
:guilabel:`Admin Tools > LLM`. The **Overview** is a guided starting point:

- a **usage & cost** band across the top — 30-day cost, requests and tokens,
  the per-provider request mix, and a daily-requests sparkline (empty until the
  first request);
- a unified **Set up & manage** grid where each module card carries its own
  setup state — *green* when it is configured, a blue *Next* flag on the single
  recommended step, and *Empty* on an optional module with no entries yet — so
  the next action is always visible without a separate wizard. Each card links
  to its module;
- the **Providers** card shows a live, **token-free** reachability indicator per
  configured provider (a model-list/health ping, never a completion);
- a **For developers** section showing how to call the same configuration from
  PHP via ``LlmServiceManager``.

.. figure:: /Images/backend-dashboard.png
   :alt: The LLM Overview — a usage-and-cost band, a status-coloured module
       card grid, and a developer section
   :class: with-border with-shadow
   :zoom: lightbox

   The LLM Overview: the usage & cost band, the state-coloured
   :guilabel:`Set up & manage` grid, and the :guilabel:`For developers`
   section.

The module has eleven sections accessible from the
left-hand navigation:

- **Overview** — guided dashboard: usage & cost, per-module setup state, and
  the developer guide
- **Providers** — API connections
- **Models** — available LLM models
- **Configurations** — use-case presets
- **Tasks** — one-shot prompt templates
- **Snippets** — tagged reusable prompt fragments
- **Setup wizard** — guided provider, model and configuration setup (admin-only)
- **Skills** — GitHub-hosted ``SKILL.md`` sources (admin-only)
- **Tools** — enable or disable the agent tools (admin-only)
- **Playground** — run the agent tool loop interactively (admin-only)
- **Analytics** — usage and cost dashboard (admin-only)

.. toctree::
   :maxdepth: 2

   Providers
   Models
   Configurations
   Tasks
   PromptSnippets
   Skills
   Tools
   Wizards
   UserBudgets
   Analytics
