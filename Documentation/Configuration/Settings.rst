.. include:: /Includes.rst.txt

.. _configuration-settings:

========
Settings
========

.. _configuration-typoscript:

TypoScript settings
===================

Runtime settings via TypoScript constants:

.. code-block:: typoscript
   :caption: Configuration/TypoScript/constants.typoscript

   plugin.tx_nrllm {
       settings {
           # Default provider (openai, claude, gemini)
           defaultProvider = openai
           # Enable response caching
           enableCaching = 1
           # Cache lifetime in seconds
           cacheLifetime = 3600
       }
   }

.. _configuration-environment:

Environment variables
=====================

.. code-block:: bash
   :caption: .env

   # TYPO3 encryption key (used for API key encryption)
   TYPO3_CONF_VARS__SYS__encryptionKey=your-key

   # Optional: Override default timeout
   TYPO3_NR_LLM_DEFAULT_TIMEOUT=60

.. _configuration-security:

Security
========

.. _configuration-security-api-keys:

API key protection
------------------

1. **Encrypted storage** — API keys use
   :php:`sodium_crypto_secretbox`.
2. **Database security** — ensure backups are
   encrypted.
3. **Backend access** — restrict the module to
   authorized administrators.
4. **Key rotation** — changing the TYPO3
   :php:`encryptionKey` requires re-encryption.

.. _configuration-security-input:

Input sanitization
------------------

Sanitize user input before sending to providers:

.. code-block:: php
   :caption: Example: Sanitizing user input

   use TYPO3\CMS\Core\Utility\GeneralUtility;

   $sanitizedInput = GeneralUtility::removeXSS(
       $userInput
   );

.. _configuration-security-output:

Output handling
---------------

Treat LLM responses as untrusted content:

.. code-block:: php
   :caption: Example: Escaping output

   $safeOutput = htmlspecialchars(
       $response->content, ENT_QUOTES, 'UTF-8'
   );

.. _configuration-logging:

Logging
=======

.. code-block:: php
   :caption: config/system/additional.php

   $GLOBALS['TYPO3_CONF_VARS']['LOG']
       ['Netresearch']['NrLlm'] = [
       'writerConfiguration' => [
           \Psr\Log\LogLevel::DEBUG => [
               \TYPO3\CMS\Core\Log\Writer\FileWriter
                   ::class => [
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

   $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']
       ['cacheConfigurations']['nrllm_responses']
       ['backend'] = \TYPO3\CMS\Core\Cache\Backend
           \RedisBackend::class;

Clear cache:

.. code-block:: bash

   vendor/bin/typo3 cache:flush --group=nrllm
