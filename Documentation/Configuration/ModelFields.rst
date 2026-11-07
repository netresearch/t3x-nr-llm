.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _configuration-model:

============
Model fields
============

Models represent specific LLM models available
through a provider.

.. figure:: /Images/backend-models.png
   :alt: Model list showing capabilities and pricing
   :class: with-border with-shadow
   :zoom: lightbox

   Model list with capability badges, context
   length, and cost columns.

.. _configuration-model-inventory:

Field inventory
===============

.. list-table:: TCA fields (0.43.20 source)
   :header-rows: 1
   :widths: 22 12 12 8 46

   * - Field
     - TCA type
     - Default
     - Required in TCA
     - Effect
   * - ``hidden``
     - check
     - ``0``
     - no
     - TYPO3 record visibility; separate from runtime is_active.
   * - ``identifier``
     - input
     - ``unset in TCA``
     - yes
     - Unique programmatic identifier.
   * - ``name``
     - input
     - ``unset in TCA``
     - yes
     - Backend display name.
   * - ``description``
     - text
     - ``unset in TCA``
     - no
     - Description for operators.
   * - ``provider_uid``
     - select
     - ``0``
     - yes
     - Provider record that owns this model.
   * - ``model_id``
     - user
     - ``unset in TCA``
     - yes
     - Provider model ID or Azure deployment name.
   * - ``context_length``
     - number
     - ``0``
     - no
     - Context-window size; zero means unknown.
   * - ``max_output_tokens``
     - number
     - ``0``
     - no
     - Model output cap used as a default when the configuration inherits.
   * - ``dimensions``
     - number
     - ``0``
     - no
     - Embedding vector size; zero means unknown.
   * - ``default_timeout``
     - number
     - ``120``
     - no
     - Model request timeout in seconds.
   * - ``capabilities``
     - select
     - ``"chat"``
     - no
     - Declared capability vocabulary; see the table below.
   * - ``capabilities_discovered``
     - input
     - ``unset in TCA``
     - no
     - Last discovered capability set; provenance metadata.
   * - ``capabilities_confirmed_at``
     - number
     - ``unset in TCA``
     - no
     - Operator confirmation timestamp; provenance metadata.
   * - ``capabilities_source``
     - input
     - ``unset in TCA``
     - no
     - Discovery source; does not prove the operation succeeded.
   * - ``cost_input``
     - number
     - ``0``
     - no
     - Input price in cents per million tokens; zero is unpriced.
   * - ``cost_output``
     - number
     - ``0``
     - no
     - Output price in cents per million tokens; zero is unpriced.
   * - ``is_active``
     - check
     - ``1``
     - no
     - Whether the record is eligible for runtime use.
   * - ``is_default``
     - check
     - ``0``
     - no
     - Installation default configuration, or default model within its provider.

.. _configuration-model-required:

Required
========

.. confval:: identifier (model)
   :name: confval-model-identifier
   :type: string
   :required: true

   Unique slug (e.g., ``gpt-5``, ``claude-sonnet``).

.. confval:: name (model)
   :name: confval-model-name
   :type: string
   :required: true

   Display name (e.g., ``GPT-5 (128K)``).

.. confval:: provider
   :name: confval-model-provider
   :type: reference
   :required: true

   Reference to the parent provider.

.. confval:: model_id
   :name: confval-model-model-id
   :type: string
   :required: true

   The API model identifier as the provider expects
   it (e.g., ``gpt-5.3-instant``,
   ``claude-sonnet-4-6``, ``gemini-3-flash``).

.. _configuration-model-optional:

Optional
========

.. confval:: context_length
   :name: confval-model-context-length
   :type: integer
   :Default: 0 (unknown)

   Maximum context window in tokens.

.. _confval-model-max-output-tokens:

.. confval:: max_output_tokens
   :name: confval-model-max-output-tokens
   :type: integer
   :Default: 0 (unknown)

   Maximum output tokens. Acts as the default
   output cap for requests whose configuration
   sets :ref:`max_tokens <confval-config-max-tokens>`
   to ``0`` and whose caller sent no per-call
   ``max_tokens`` option.

.. confval:: dimensions
   :name: confval-model-dimensions
   :type: integer
   :Default: 0 (unknown)

   Embedding vector dimensionality. Acts as the
   default vector size for embedding requests
   whose options left ``dimensions`` unset.

.. confval:: capabilities
   :name: confval-model-capabilities
   :type: string (CSV)
   :Default: chat

   Stored capability declarations, not a proof that an adapter implements an
   operation. The vocabulary is complete below. Require only capabilities
   that the chosen discovery path or operator actually populated.

.. _configuration-model-capability-vocabulary:

Capability vocabulary
=====================

.. list-table:: Declarations and consumer limits
   :header-rows: 1

   * - Value
     - Meaning and limits
   * - ``chat``
     - Text conversation; seeded by discoverers. Used by chat eligibility.
   * - ``completion``
     - Alias for chat semantics; not seeded automatically. Prefer ``chat``.
   * - ``embeddings``
     - Vector generation; seeded by Gemini/Ollama discovery. Verify other models.
   * - ``vision``
     - Image input; reported or catalogue-derived. Used by vision eligibility.
   * - ``streaming``
     - Streaming declaration; missing provenance does not prove no support.
   * - ``tools``
     - Tool-calling declaration; used by tool-operation eligibility.
   * - ``json_mode``
     - Request-format vocabulary; not seeded or consulted as an operation gate.
   * - ``audio``
     - Chat audio modality declaration; current message carriers do not carry
       arbitrary audio content parts.
   * - ``image``
     - Specialized image generation; separate from criteria-mode text calls.
   * - ``text_to_speech``
     - Specialized speech synthesis; separate service API.
   * - ``transcription``
     - Specialized audio transcription; separate service API.
   * - ``decision``
     - Native typed decisions; populated by the decision discoverers.

Provenance distinguishes reported values, bundled catalogue knowledge,
assumptions and operator confirmation. A checkbox alone is not a successful
provider test. Operation enforcement follows the configured routing policy;
see :ref:`administration-models`.

.. confval:: cost_input
   :name: confval-model-cost-input
   :type: decimal
   :Default: 0

   Cost per 1M input tokens in cents, with up
   to two decimals — 0.042 USD is ``4.2``.
   ``0`` means unpriced: no cost is recorded.

.. confval:: cost_output
   :name: confval-model-cost-output
   :type: decimal
   :Default: 0

   Cost per 1M output tokens in cents, with up
   to two decimals.

.. confval:: is_default
   :name: confval-model-is-default
   :type: boolean
   :Default: false

   Mark as default model for this provider.
