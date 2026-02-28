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

1. Activate the extension in :guilabel:`Admin Tools > Extension Manager`.
2. Configure providers and API keys in :guilabel:`Admin Tools > LLM > Providers`.
3. Define available models in :guilabel:`Admin Tools > LLM > Models`.
4. Create configurations in :guilabel:`Admin Tools > LLM > Configurations`.
5. Clear caches.

.. _installation-composer:

Composer installation
=====================

.. _installation-requirements:

Requirements
------------

Ensure your system meets these requirements:

- PHP 8.2 or higher.
- TYPO3 v13.4 or higher.
- Composer 2.x.

.. _installation-steps:

Installation steps
------------------

1. **Add the package**

   .. code-block:: bash
      :caption: Install via Composer

      composer require netresearch/nr-llm

2. **Activate the extension**

   Navigate to :guilabel:`Admin Tools > Extension Manager` and activate :t3ext:`nr_llm`.

3. **Configure API keys**

   See :ref:`configuration` for detailed setup instructions.

4. **Clear caches**

   .. code-block:: bash
      :caption: Flush all caches

      vendor/bin/typo3 cache:flush

.. _installation-manual:

Manual installation
===================

If you cannot use Composer:

1. Download the extension from the TYPO3 Extension Repository (TER).
2. Extract to :path:`typo3conf/ext/nr_llm`.
3. Activate in :guilabel:`Admin Tools > Extension Manager`.
4. Configure API keys and settings.

.. warning::

   Manual installation requires manual dependency management.
   Composer installation is strongly recommended.

.. _installation-database:

Database setup
==============

The extension creates the following database tables automatically:

.. list-table::
   :header-rows: 1
   :widths: 30 70

   * - Table
     - Purpose
   * - :sql:`tx_nrllm_provider`
     - Stores API provider connections with encrypted credentials.
   * - :sql:`tx_nrllm_model`
     - Stores available LLM models with capabilities and pricing.
   * - :sql:`tx_nrllm_configuration`
     - Stores use-case-specific configurations with prompts and parameters.
   * - :sql:`tx_nrllm_prompt_template`
     - Stores reusable prompt templates.

Run the database compare tool after installation:

.. code-block:: bash
   :caption: Set up extension database tables

   vendor/bin/typo3 extension:setup nr_llm

.. _installation-cache:

Cache configuration
===================

The extension uses TYPO3's caching framework. Default configuration is
automatically set up, but you can customize it:

.. code-block:: php
   :caption: config/system/additional.php

   $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nrllm_responses'] = [
       'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
       'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
       'options' => [
           'defaultLifetime' => 3600,
       ],
       'groups' => ['nrllm'],
   ];

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

      composer update netresearch/nr-llm

3. Run database migrations:

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

1. Deactivate in :guilabel:`Admin Tools > Extension Manager`.
2. Remove via Composer:

   .. code-block:: bash
      :caption: Remove the extension

      composer remove netresearch/nr-llm

3. Clean up database tables if desired:

   .. code-block:: sql
      :caption: Drop extension database tables

      DROP TABLE IF EXISTS tx_nrllm_provider;
      DROP TABLE IF EXISTS tx_nrllm_model;
      DROP TABLE IF EXISTS tx_nrllm_configuration;
      DROP TABLE IF EXISTS tx_nrllm_prompt_template;

4. Remove any TypoScript includes referencing the extension.
