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
