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

.. toctree::
   :maxdepth: 2

   Providers
   Models
   Configurations
   Tasks
   Wizards
   UserBudgets
