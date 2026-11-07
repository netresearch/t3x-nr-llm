.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _installation:

============
Installation
============

.. _quickstart:

Quick start
===========

The recommended way to install this extension is via Composer:

.. code-block:: bash
   :caption: Install via Composer

   composer require netresearch/nr-llm

After installation:

1. Run :bash:`vendor/bin/typo3 extension:setup` to create the schema and
   finish package setup. Composer-installed extensions are already active.
2. Run the setup wizard, the first entry in :guilabel:`AI > Setup`. It
   creates a provider, its models and a configuration in one pass. While no
   provider exists, :guilabel:`AI > Overview` opens with a button to it, and
   on TYPO3 v14 the :guilabel:`AI > Setup` overview does too.
3. Adjust what the wizard created, or add more, in
   :guilabel:`AI > Setup > Providers`, :guilabel:`Models` and
   :guilabel:`Configurations`.
4. Clear caches.

.. _installation-composer:

Composer installation
=====================

.. _installation-requirements:

Requirements
------------

Ensure your system meets these requirements:

- PHP 8.2 or higher.
- TYPO3 v13.4 LTS or v14.3 LTS.
- Composer 2.x.
- :composer:`netresearch/nr-vault` ^0.16.0 or ^1.1 (required for
  API key encryption; installed automatically via
  Composer).

.. _installation-steps:

Installation steps
------------------

1. **Add the package**

   .. code-block:: bash
      :caption: Install via Composer

      composer require netresearch/nr-llm

2. **Set up the installed packages**

   .. code-block:: bash
      :caption: Initialize installed extensions

      vendor/bin/typo3 extension:setup

   TYPO3 treats Composer-installed extensions as active. There is no separate
   activation in the Extension Manager.

3. **Configure API keys**

   Use the setup wizard at
   :guilabel:`AI > Setup > Setup Wizard`
   to auto-detect your provider and discover models.

   .. figure:: /Images/backend-setup-wizard.png
      :alt: LLM setup wizard
      :class: with-border with-shadow
      :zoom: lightbox

      The setup wizard guides you through provider
      connection, model discovery, and configuration.

   .. tip::

      Not sure which provider you need? :guilabel:`AI > Setup > Get
      Started` asks what you want to do first — editorial assistance,
      translation, metadata, media accessibility, agent workflows, developer
      integration — and can install a use-case pack for it: a configuration,
      tasks and prompt snippets, created only after you confirm the list. The
      *Editorial Starter* pack is the one currently shipped. The setup wizard
      stays the technical route and is linked from every screen there.

      The plan you confirm also states the three things an install changes that
      are not new records: the snippet tags it adds to the pack's own
      configuration, any existing configuration that already selects one of
      those tags and would therefore compose the pack's snippets into its
      prompts too, and any existing snippet carrying one of those tags, which
      the added tags compose into the pack's configuration. See
      :ref:`administration-usecase-packs`.

   See :ref:`configuration` for detailed setup
   instructions.

4. **Clear caches**

   .. code-block:: bash
      :caption: Flush all caches

      vendor/bin/typo3 cache:flush

.. _installation-manual:

Composer is required
====================

This guide supports Composer installations. The required nr-vault dependency
also requires Composer; extracting a TER archive alone does not install a
supported dependency chain.

.. _installation-database:

Database setup
==============

Run ``extension:setup`` after Composer installation. The table below is an
orientation, not a complete schema inventory; :file:`ext_tables.sql` is the
versioned source for all extension-owned tables and added core-table fields.

.. list-table::
   :header-rows: 1
   :widths: 30 70

   * - Table
     - Purpose
   * - :sql:`tx_nrllm_provider`
     - Stores provider connections and nr-vault credential identifiers.
   * - :sql:`tx_nrllm_model`
     - Stores available LLM models with capabilities and pricing.
   * - :sql:`tx_nrllm_configuration`
     - Stores use-case-specific configurations with prompts and parameters.
   * - :sql:`tx_nrllm_task`
     - Stores one-shot prompt tasks for common operations.
   * - :sql:`tx_nrllm_promptsnippet`
     - Stores tagged prompt fragments for configuration composition.
   * - :sql:`tx_nrllm_ai_session` / :sql:`tx_nrllm_ai_session_message`
     - Stores conversations and their messages.
   * - :sql:`tx_nrllm_agentrun` / :sql:`tx_nrllm_agentrun_event`
     - Stores run state, approvals and privacy-filtered events.
   * - :sql:`tx_nrllm_service_usage`
     - Tracks LLM and specialized-service usage.

Run the database compare tool after installation:

.. code-block:: bash
   :caption: Set up extension database tables

   vendor/bin/typo3 extension:setup

.. _installation-cache:

Cache configuration
===================

The extension uses TYPO3's caching framework. Cache
configuration is set up automatically — **no backend
is hardcoded**. TYPO3 uses your instance's default
cache backend, so Redis, Valkey, or Memcached work
transparently if configured.

To override the cache backend specifically for nr-llm:

.. code-block:: php
   :caption: config/system/additional.php

   use TYPO3\CMS\Core\Cache\Backend\RedisBackend;

   $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']
       ['cacheConfigurations']['nrllm_responses']
       ['backend'] = RedisBackend::class;

.. _installation-upgrading:

Upgrading
=========

.. _installation-upgrading-previous:

From previous versions
----------------------

1. **Backup your database** before upgrading.
2. Run Composer update:

   .. code-block:: bash
      :caption: Update the extension

      composer update netresearch/nr-llm --with-all-dependencies

3. Run package setup, inspect schema changes and list pending upgrade wizards:
   ``vendor/bin/typo3 extension:setup`` and
   ``vendor/bin/typo3 upgrade:list``. Review each applicable wizard before
   running it with ``vendor/bin/typo3 upgrade:run <identifier>``.
   These include provider timeouts, enforcement defaults and skill-version
   digests; a skill digest is not a new approval.

   Apply required schema additions:

   .. code-block:: bash
      :caption: Update database schema

      vendor/bin/typo3 database:updateschema

4. Clear all caches:

   .. code-block:: bash
      :caption: Flush all caches

      vendor/bin/typo3 cache:flush

.. _installation-breaking-changes:

Breaking changes
----------------

Check the :ref:`changelog` for breaking changes between versions.

.. _installation-uninstall:

Uninstallation
==============

To remove the extension:

1. Stop nr-llm workers and scheduled jobs, remove consumer integrations and
   take a database backup. Decide which retained records must be exported.
2. Remove via Composer:

   .. code-block:: bash
      :caption: Remove the extension

      composer remove netresearch/nr-llm

3. Back up the database before removing retained data. The following removes
   all nr-llm-owned tables in this version, including conversations, runs,
   approvals, usage and OAuth grants. Review the installed version's schema
   before running it. It does not remove nr-llm fields on core tables or
   secrets held separately in nr-vault; review those separately.

   Clean up the extension-owned tables if desired:

   .. code-block:: sql
      :caption: Drop extension database tables

      DROP TABLE IF EXISTS tx_nrllm_provider;
      DROP TABLE IF EXISTS tx_nrllm_model;
      DROP TABLE IF EXISTS tx_nrllm_configuration;
      DROP TABLE IF EXISTS tx_nrllm_user_budget;
      DROP TABLE IF EXISTS tx_nrllm_configuration_begroups_mm;
      DROP TABLE IF EXISTS tx_nrllm_task;
      DROP TABLE IF EXISTS tx_nrllm_promptsnippet;
      DROP TABLE IF EXISTS tx_nrllm_term_list;
      DROP TABLE IF EXISTS tx_nrllm_glossary;
      DROP TABLE IF EXISTS tx_nrllm_service_usage;
      DROP TABLE IF EXISTS tx_nrllm_skill_source;
      DROP TABLE IF EXISTS tx_nrllm_skill;
      DROP TABLE IF EXISTS tx_nrllm_skill_approval;
      DROP TABLE IF EXISTS tx_nrllm_task_skill_mm;
      DROP TABLE IF EXISTS tx_nrllm_configuration_skill_mm;
      DROP TABLE IF EXISTS tx_nrllm_tool_state;
      DROP TABLE IF EXISTS tx_nrllm_tool_group_state;
      DROP TABLE IF EXISTS tx_nrllm_telemetry;
      DROP TABLE IF EXISTS tx_nrllm_skill_audit;
      DROP TABLE IF EXISTS tx_nrllm_eval_result;
      DROP TABLE IF EXISTS tx_nrllm_agentrun;
      DROP TABLE IF EXISTS tx_nrllm_agentrun_event;
      DROP TABLE IF EXISTS tx_nrllm_ai_session;
      DROP TABLE IF EXISTS tx_nrllm_ai_session_message;
      DROP TABLE IF EXISTS tx_nrllm_governance_event;
      DROP TABLE IF EXISTS tx_nrllm_mcp_server;
      DROP TABLE IF EXISTS tx_nrllm_mcp_tool;
      DROP TABLE IF EXISTS tx_nrllm_call_outcome;
      DROP TABLE IF EXISTS tx_nrllm_worker_heartbeat;
      DROP TABLE IF EXISTS tx_nrllm_mcp_oauth_grant;

4. Review added page/content columns with TYPO3's schema comparison. Remove
   only fields no longer used by an installed extension. Separately remove
   obsolete nr-llm grants from the groups' existing custom-permission field;
   these grants are not separate nr-llm columns on backend-user/group tables.
5. Flush the ``nrllm`` cache group and remove scheduled purge/worker jobs.
6. Review credentials and OAuth grants in nr-vault separately. Deleting
   nr-llm tables does not delete shared vault secrets. Revoke credentials at
   the provider only after checking other consumers.
7. Apply the organisation's backup/export retention policy. Live database
   cleanup does not erase earlier backups, logs or external provider data.
