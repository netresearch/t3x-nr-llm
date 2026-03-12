.. include:: /Includes.rst.txt

.. _start:

===================
TYPO3 LLM extension
===================

:Extension key:
   nr_llm

:Package name:
   :composer:`netresearch/nr-llm`

:Version:
   |release|

:Language:
   en

:Author:
   Netresearch DTT GmbH

:License:
   This document is published under the
   `GPL-2.0-or-later <https://www.gnu.org/licenses/gpl-2.0.html>`__ license.

:Rendered:
   |today|

----

Shared AI foundation for TYPO3. Configure LLM
providers once — every AI extension uses them.
Supports OpenAI, Anthropic Claude, Google Gemini,
Ollama, and more.

.. figure:: /Images/backend-dashboard.png
   :alt: LLM backend module dashboard showing
       provider and model management, AI wizard
       buttons, and quick-reference code snippets
   :class: with-border with-shadow
   :zoom: lightbox

   The :guilabel:`Admin Tools > LLM` backend module.

----

Getting started
===============

.. card-grid::
   :columns: 1
   :columns-md: 2
   :gap: 4
   :card-height: 100

   .. card:: 📘 Introduction

      Learn what nr-llm is, which providers are
      supported, and what problems it solves.

      .. card-footer:: :ref:`Read more <introduction>`
         :button-style: btn btn-secondary stretched-link

   .. card:: 📦 Installation

      Install nr-llm via Composer and activate it.

      .. card-footer:: :ref:`Read more <quickstart>`
         :button-style: btn btn-primary stretched-link

----

For administrators
==================

Set up and manage AI providers, models, and
configurations through the TYPO3 backend module.

.. card-grid::
   :columns: 1
   :columns-md: 3
   :gap: 4
   :card-height: 100

   .. card:: 🛠️ Administration guide

      Step-by-step: add providers, fetch models,
      create configurations and tasks. Includes
      screenshots of every screen.

      .. card-footer:: :ref:`Read more <administration>`
         :button-style: btn btn-primary stretched-link

   .. card:: ✨ AI-powered wizards

      Setup wizard, configuration wizard, and
      task wizard — let AI generate your config
      from a plain-language description.

      .. card-footer:: :ref:`Read more <administration-wizards>`
         :button-style: btn btn-primary stretched-link

   .. card:: 📋 Configuration reference

      Complete field reference for providers,
      models, configurations, TypoScript settings,
      security, and caching.

      .. card-footer:: :ref:`Read more <configuration>`
         :button-style: btn btn-secondary stretched-link

----

For developers
==============

Build your TYPO3 extension on nr-llm — three lines
of dependency injection, no API key handling.

.. card-grid::
   :columns: 1
   :columns-md: 3
   :gap: 4
   :card-height: 100

   .. card:: 🚀 Integration guide

      Step-by-step tutorial: add AI capabilities
      to your extension in five minutes.

      .. card-footer:: :ref:`Read more <integration-guide>`
         :button-style: btn btn-primary stretched-link

   .. card:: 💻 Developer guide

      LlmServiceManager API, streaming, tool
      calling, and custom providers.

      .. card-footer:: :ref:`Read more <developer>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ⚙️ Feature services

      Translation, vision, embeddings, and
      completion — ready to inject and use.

      .. card-footer:: :ref:`Read more <feature-services>`
         :button-style: btn btn-secondary stretched-link

   .. card:: 📚 API reference

      Complete class and method reference for
      all public services and response objects.

      .. card-footer:: :ref:`Read more <api-reference>`
         :button-style: btn btn-secondary stretched-link

   .. card:: 🏗️ Architecture

      Three-tier configuration hierarchy,
      provider abstraction, and design decisions.

      .. card-footer:: :ref:`Read more <architecture>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ✅ Testing

      Test infrastructure, mocking LLM services,
      and CI configuration.

      .. card-footer:: :ref:`Read more <testing>`
         :button-style: btn btn-secondary stretched-link

----

**Table of contents**

.. toctree::
   :maxdepth: 2
   :titlesonly:

   Introduction/Index
   Installation/Index
   Administration/Index
   Configuration/Index
   Developer/Index
   Api/Index
   Architecture/Index
   Testing/Index
   Adr/Index
   Changelog

.. Meta Menu

.. toctree::
   :hidden:

   Sitemap
