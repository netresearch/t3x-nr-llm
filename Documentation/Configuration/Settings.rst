.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _configuration-settings:

========
Settings
========

.. _configuration-typoscript:
.. _configuration-providers:

Provider configuration
=======================

Providers, models and configurations are **database-backed** and managed
in the LLM backend module — not via TypoScript. nr-llm does **not** read
``plugin.tx_nrllm`` TypoScript settings; any such constants/setup have no
effect (this is true for both classic TypoScript templates and site sets).

To make the generic ``chat()`` / ``complete()`` entry points work without
pinning a provider per call, set up a default configuration:

#. Open the **LLM** backend module.
#. Create a **Provider** (e.g. OpenAI) and store its API key as an
   nr-vault identifier — see :ref:`configuration-security-api-keys`.
#. Create a **Model** for that provider.
#. Create a **Configuration** bundling the model, then mark it
   **active** and **default**.

The :guilabel:`Setup Wizard` in the module walks through these steps.

Without an active default configuration, generic calls throw
*"No provider specified and no default provider configured"*.

.. _configuration-field-fill:

Filling empty fields on save
============================

An installation can have empty fields written by AI after a save
(:ref:`ADR-237 <adr-237>`). The settings are in the extension configuration of
``nr_llm``, section *fieldFill*. Nothing happens until ``fieldFill.fields`` is set.

.. confval:: fieldFill.fields
   :name: ext-nr-llm-field-fill-fields
   :type: string
   :default: (empty)

   Comma-separated ``table.field`` entries, for example
   ``pages.description,pages.abstract``. Only plain text fields count: a rich text
   field, a code editor, a slug or a relation stays out, and so do accounts and
   ``sys_`` tables.

.. confval:: fieldFill.maxLength
   :name: ext-nr-llm-field-fill-max-length
   :type: integer
   :default: 300

   The longest text written, in characters. The TCA ``max`` of the field applies
   when it is lower. The model is asked for a tenth less, and a longer answer is
   not written.

When an editor changes and saves a record with such a field empty, a background
run is queued and the save ends. A save that changes nothing queues nothing. A worker has to run (:ref:`administration-agent-runs-background`). It writes
the text as the user who saved, and only while the field is still empty. A failed
run writes nothing, and :guilabel:`Agent Runs` shows what became of each answer.

Nothing is queued for a field the save itself emptied, for a page with hardly any
text, for ``_cli_`` or an import, for a copy or a translation made by TYPO3, or
for a user who may not use the default configuration. The record's text is sent
to the provider of the default configuration.

.. _configuration-vision:

Vision editor actions
=====================

The editor actions :guilabel:`Set alternative text` and
:guilabel:`Update file metadata` send the image of the file to the model
(:ref:`ADR-235 <adr-235>`). The settings are in the extension configuration of
``nr_llm``, section *vision*.

.. confval:: vision.maxImageEdge
   :name: vision-maxImageEdge
   :type: integer
   :default: 1024

   The longest edge in pixels of the image sent to the model. TYPO3's file
   processing scales it and keeps the result in the processed folder. An image
   that cannot be brought to this size is not sent, and the action is not
   available for it. Core remembers a failed attempt: an image tried while the
   image processor was broken stays unavailable until the processed files are
   cleared in :guilabel:`Admin Tools > Maintenance`.

.. confval:: vision.excludedFolders
   :name: vision-excludedFolders
   :type: string
   :default: (empty)

   Comma-separated combined folder identifiers, for example
   ``1:/private/, 1:/hr/``. No image from these folders or their subfolders
   reaches a model.

.. confval:: vision.<field>.enabled
   :name: vision-field-enabled
   :type: boolean
   :default: 1

   For ``alternative``, ``title`` and ``description``. Whether the editor action
   fills this field. An action with no field left is not offered. This does not
   switch off the tool: the assistant may still use it, which the
   :guilabel:`Tools` module governs.

.. confval:: vision.<field>.maxLength
   :name: vision-field-maxLength
   :type: integer
   :default: 0

   The longest value in characters. It applies to every writer of the field,
   the assistant included, and it cannot lift a narrower TCA ``max``. The model
   is asked for a tenth less, and a longer value is refused on the approval card.
   ``0`` sets no limit of its own.

.. confval:: vision.<field>.prompt
   :name: vision-field-prompt
   :type: string
   :default: (empty)

   What the model is told to write for this field. Empty uses the shipped text.

The action is not offered when the default configuration's model declares
capabilities without ``vision``. A model with an empty capability field passes. A
configuration that selects its model by criteria has to require ``vision`` in its
criteria.
The prompt asks for the language of the backend user.

.. _configuration-environment:

Environment variables
=====================

.. code-block:: bash
   :caption: .env

   # TYPO3 encryption key (used for API key encryption)
   TYPO3_CONF_VARS__SYS__encryptionKey=your-key

.. _configuration-security:

Security
========

.. _configuration-security-api-keys:

API key protection
------------------

1. **Encrypted storage** — API keys are stored as
   vault identifiers (UUIDs) via the
   `nr-vault <https://github.com/netresearch/t3x-nr-vault>`__
   extension, which uses envelope encryption.
   nr-llm never stores raw API keys.
2. **Database security** — the database only contains
   vault UUIDs, not secrets. Ensure backups are
   encrypted regardless.
3. **Backend access** — restrict the LLM module to
   authorized administrators.
4. **Key rotation** — re-encrypt via nr-vault's
   key rotation mechanism.

.. _configuration-security-input:

Input sanitization
------------------

Sanitize user input before sending to providers:

.. code-block:: php
   :caption: Example: Sanitizing user input

   // Strip markup and control characters from free-text input before it is
   // sent to a provider. (GeneralUtility::removeXSS() was removed from the
   // TYPO3 core and must not be used.)
   $sanitizedInput = trim(strip_tags($userInput));

   $response = $adapter->chatCompletion([
       ['role' => 'user', 'content' => $sanitizedInput],
   ]);

.. _configuration-security-output:

Output handling
---------------

Treat LLM responses as untrusted content:

.. code-block:: php
   :caption: Example: Escaping output

   $response = $adapter->chatCompletion([
       ['role' => 'user', 'content' => $prompt],
   ]);

   $safeOutput = htmlspecialchars(
       $response->content, ENT_QUOTES, 'UTF-8'
   );

.. _configuration-logging:

Logging
=======

.. code-block:: php
   :caption: config/system/additional.php

   use Psr\Log\LogLevel;
   use TYPO3\CMS\Core\Log\Writer\FileWriter;

   $GLOBALS['TYPO3_CONF_VARS']['LOG']
       ['Netresearch']['NrLlm'] = [
       'writerConfiguration' => [
           LogLevel::DEBUG => [
               FileWriter::class => [
                   'logFileInfix' => 'nr_llm',
               ],
           ],
       ],
   ];

Log files: :file:`var/log/typo3_nr_llm_*.log`

.. _configuration-caching:

Caching
=======

The extension uses TYPO3's caching framework with
cache identifier ``nrllm_responses``.

**No cache backend is specified** — TYPO3 automatically
uses the instance's default cache backend. If your
instance has Redis, Valkey, or Memcached configured,
nr-llm uses it transparently with zero configuration.

- **Cache identifier**: ``nrllm_responses``
- **Cache group**: ``nrllm``
- **Default TTL**: 3600 seconds (1 hour)
- **Embeddings TTL**: 86400 seconds (24 hours)

To override the backend for this cache specifically:

.. code-block:: php
   :caption: config/system/additional.php

   use TYPO3\CMS\Core\Cache\Backend\RedisBackend;

   $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']
       ['cacheConfigurations']['nrllm_responses']
       ['backend'] = RedisBackend::class;

Clear cache:

.. code-block:: bash

   vendor/bin/typo3 cache:flush --group=nrllm
