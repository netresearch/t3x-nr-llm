.. include:: /Includes.rst.txt

.. _configuration-llm:

==========================
Configuration field reference
==========================

Configurations define use-case presets with model
selection and parameters.

.. figure:: /Images/backend-configurations.png
   :alt: Configuration list with model assignments
   :class: with-border with-shadow
   :zoom: lightbox

   Configuration list showing linked model,
   use-case type, and parameters.

.. _configuration-llm-required:

Required
========

.. confval:: identifier (config)
   :name: confval-config-identifier
   :type: string
   :required: true

   Unique slug (e.g., ``blog-summarizer``).

.. confval:: name (config)
   :name: confval-config-name
   :type: string
   :required: true

   Display name (e.g., ``Blog Post Summarizer``).

.. confval:: model
   :name: confval-config-model
   :type: reference
   :required: true

   Reference to the model to use.

.. confval:: system_prompt
   :name: confval-config-system-prompt
   :type: text
   :required: true

   System message that sets the AI's behavior.

.. _configuration-llm-optional:

Optional
========

.. confval:: temperature
   :name: confval-config-temperature
   :type: float
   :Default: 0.7

   Creativity (0.0 = deterministic, 2.0 = creative).

.. confval:: max_tokens (config)
   :name: confval-config-max-tokens
   :type: integer
   :Default: (model default)

   Maximum response length in tokens.

.. confval:: top_p
   :name: confval-config-top-p
   :type: float
   :Default: 1.0

   Nucleus sampling (0.0-1.0).

.. confval:: frequency_penalty
   :name: confval-config-frequency-penalty
   :type: float
   :Default: 0.0

   Reduces word repetition (-2.0 to 2.0).

.. confval:: presence_penalty
   :name: confval-config-presence-penalty
   :type: float
   :Default: 0.0

   Encourages topic diversity (-2.0 to 2.0).

.. confval:: use_case_type
   :name: confval-config-use-case-type
   :type: string
   :Default: chat

   Task type: ``chat``, ``completion``,
   ``embedding``, ``translation``.

.. confval:: fallback_chain
   :name: confval-config-fallback-chain
   :type: JSON (text column)
   :Default: (empty)

   JSON object with a single key,
   ``configurationIdentifiers``, whose value is the
   ordered list of other configuration identifiers
   to retry against when the primary fails with a
   retryable error (connection error, HTTP 5xx, or
   HTTP 429 rate-limit). Non-retryable errors bubble
   up unchanged. Streaming requests do not trigger
   fallback — chunks cannot be replayed against a
   different provider.

   Example payload::

       {"configurationIdentifiers": ["claude-sonnet", "ollama-local"]}

   Identifiers are matched case-insensitively;
   leave empty to disable fallback. See
   :ref:`developer-fallback-chain`.
