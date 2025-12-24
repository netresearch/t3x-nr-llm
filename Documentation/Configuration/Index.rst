.. include:: /Includes.rst.txt

.. _configuration:

=============
Configuration
=============

.. _extension-configuration:

Extension Configuration
=======================

Configure the extension in **Admin Tools > Settings > Extension Configuration > nr_llm**.

Provider Settings
-----------------

.. confval:: openai_api_key

   :type: string
   :Default: (empty)

   Your OpenAI API key. Obtain from https://platform.openai.com/api-keys

.. confval:: openai_default_model

   :type: string
   :Default: gpt-4.1

   Default model for OpenAI requests. Options:

   - ``gpt-5`` - Most capable model
   - ``gpt-4.1`` - Recommended balanced model
   - ``gpt-4.1-mini`` - Smaller, faster variant
   - ``gpt-4.1-nano`` - Fast, cost-effective
   - ``o3`` - Advanced reasoning model
   - ``o4-mini`` - Smaller reasoning model

.. confval:: claude_api_key

   :type: string
   :Default: (empty)

   Your Anthropic API key. Obtain from https://console.anthropic.com

.. confval:: claude_default_model

   :type: string
   :Default: claude-sonnet-4-5-20250929

   Default model for Claude requests. Options:

   - ``claude-opus-4-5-20251124`` - Most capable (November 2025)
   - ``claude-sonnet-4-5-20250929`` - Recommended balanced model
   - ``claude-opus-4-1-20250805`` - Previous Opus
   - ``claude-opus-4-20250514`` - Claude 4 Opus
   - ``claude-sonnet-4-20250514`` - Claude 4 Sonnet

.. confval:: gemini_api_key

   :type: string
   :Default: (empty)

   Your Google Gemini API key. Obtain from https://aistudio.google.com/apikey

.. confval:: gemini_default_model

   :type: string
   :Default: gemini-3-flash-preview

   Default model for Gemini requests. Options:

   - ``gemini-3-flash-preview`` - Latest flash model (December 2025)
   - ``gemini-3-pro`` - Most capable
   - ``gemini-2.5-flash`` - Stable flash model
   - ``gemini-2.5-pro`` - Stable pro model
   - ``gemini-2.5-flash-lite`` - Fast, cost-effective

General Settings
----------------

.. confval:: default_provider

   :type: string
   :Default: openai
   :Options: openai, claude, gemini

   The default provider used when none is specified in requests.

.. confval:: request_timeout

   :type: int
   :Default: 60

   HTTP request timeout in seconds. Increase for longer operations.

.. confval:: cache_lifetime

   :type: int
   :Default: 3600

   Default cache lifetime in seconds for API responses.

.. _typoscript-configuration:

TypoScript Configuration
========================

Constants
---------

.. code-block:: typoscript
   :caption: TypoScript Constants

   plugin.tx_nrllm {
       settings {
           # Default provider (openai, claude, gemini)
           defaultProvider = openai

           # Default temperature (0.0-2.0)
           defaultTemperature = 0.7

           # Maximum tokens for responses
           defaultMaxTokens = 1000

           # Cache lifetime in seconds
           cacheLifetime = 3600

           # Enable/disable response caching
           enableCaching = 1

           # Enable streaming by default
           enableStreaming = 0
       }
   }

Setup
-----

.. code-block:: typoscript
   :caption: TypoScript Setup

   plugin.tx_nrllm {
       settings {
           # Provider-specific overrides
           providers {
               openai {
                   model = gpt-4.1
                   temperature = 0.7
                   maxTokens = 2000
               }
               claude {
                   model = claude-sonnet-4-5-20250929
                   temperature = 0.5
                   maxTokens = 4000
               }
           }

           # Feature service defaults
           services {
               completion {
                   temperature = 0.7
                   maxTokens = 1000
               }
               embedding {
                   cacheTtl = 86400
               }
               translation {
                   formality = default
                   preserveFormatting = 1
               }
               vision {
                   detailLevel = auto
               }
           }
       }
   }

.. _services-yaml-configuration:

Services.yaml Configuration
===========================

Customize dependency injection in your extension:

.. code-block:: yaml
   :caption: Configuration/Services.yaml

   services:
     _defaults:
       autowire: true
       autoconfigure: true
       public: false

     # Override default provider
     Netresearch\NrLlm\Service\LlmServiceManager:
       arguments:
         $defaultProvider: 'claude'

     # Register custom provider
     MyVendor\MyExtension\Provider\CustomProvider:
       tags:
         - name: nr_llm.provider
           priority: 100

.. _environment-variables:

Environment Variables
=====================

For sensitive credentials, use environment variables:

.. code-block:: bash
   :caption: .env

   TYPO3_NR_LLM_OPENAI_API_KEY=sk-...
   TYPO3_NR_LLM_CLAUDE_API_KEY=sk-ant-...
   TYPO3_NR_LLM_GEMINI_API_KEY=AIza...

Reference in configuration:

.. code-block:: php
   :caption: config/system/additional.php

   $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm']['openai_api_key'] =
       getenv('TYPO3_NR_LLM_OPENAI_API_KEY');

.. _security-considerations:

Security Considerations
=======================

API Key Protection
------------------

1. **Never commit API keys** to version control
2. Use environment variables or secrets management
3. Restrict backend access to authorized users only
4. Implement rate limiting for public-facing features

Input Sanitization
------------------

Always sanitize user input before sending to LLM providers:

.. code-block:: php

   use TYPO3\CMS\Core\Utility\GeneralUtility;

   $sanitizedInput = GeneralUtility::removeXSS($userInput);
   $response = $llmManager->complete($sanitizedInput);

Output Handling
---------------

Treat LLM responses as untrusted content:

.. code-block:: php

   use TYPO3\CMS\Core\Utility\GeneralUtility;

   $response = $llmManager->complete($prompt);
   $safeOutput = htmlspecialchars($response->content, ENT_QUOTES, 'UTF-8');

.. _rate-limiting-configuration:

Rate Limiting
=============

Configure rate limits to protect against excessive API usage:

.. code-block:: php
   :caption: config/system/additional.php

   $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm']['rate_limiting'] = [
       'enabled' => true,
       'requests_per_minute' => 60,
       'requests_per_day' => 1000,
       'tokens_per_day' => 100000,
   ];

.. _logging-configuration:

Logging Configuration
=====================

Enable detailed logging for debugging:

.. code-block:: php
   :caption: config/system/additional.php

   $GLOBALS['TYPO3_CONF_VARS']['LOG']['Netresearch']['NrLlm'] = [
       'writerConfiguration' => [
           \Psr\Log\LogLevel::DEBUG => [
               \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
                   'logFileInfix' => 'nr_llm',
               ],
           ],
       ],
   ];

Log file location: ``var/log/typo3_nr_llm_*.log``
