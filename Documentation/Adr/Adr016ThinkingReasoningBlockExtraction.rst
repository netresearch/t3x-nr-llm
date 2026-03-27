.. include:: /Includes.rst.txt

.. _adr-016:

==============================================
ADR-016: Thinking/Reasoning Block Extraction
==============================================

:Status: Accepted
:Date: 2025-12
:Authors: Netresearch DTT GmbH

.. _adr-016-context:

Context
=======

Modern reasoning models emit structured thinking blocks alongside their final
output. Anthropic Claude uses native ``thinking`` content blocks in its API
response. DeepSeek, Qwen, and other models wrap reasoning in
``<think>...</think>`` XML tags within the text content. These blocks should be
accessible for debugging and transparency but must not
pollute the main response.

.. _adr-016-decision:

Decision
========

Extract thinking blocks from LLM responses using a two-tier strategy:

1. **Native extraction** -- Provider-specific structured thinking blocks
   (Anthropic ``type: "thinking"`` content blocks).
2. **Regex fallback** -- ``<think>...</think>`` tag extraction for models that
   embed reasoning inline (DeepSeek, Qwen, local models via Ollama/OpenRouter).

:php:`CompletionResponse` carries an optional ``thinking`` property:

.. code-block:: php
   :caption: CompletionResponse with thinking support

   final readonly class CompletionResponse
   {
       public function __construct(
           public string $content,
           public string $model,
           public UsageStatistics $usage,
           public string $finishReason = 'stop',
           public string $provider = '',
           public ?array $toolCalls = null,
           public ?array $metadata = null,
           public ?string $thinking = null,  // Extracted thinking content
       ) {}

       public function hasThinking(): bool
       {
           return $this->thinking !== null && trim($this->thinking) !== '';
       }
   }

The base :php:`AbstractProvider` implements the shared regex extraction:

.. code-block:: php
   :caption: AbstractProvider::extractThinkingBlocks()

   protected function extractThinkingBlocks(string $content): array
   {
       $thinking = null;
       if (preg_match_all('#<think>([\s\S]*?)</think>#i', $content, $matches)) {
           $thinking = trim(implode("\n", $matches[1]));
           $cleaned = preg_replace('#<think>[\s\S]*?</think>#i', ' ', $content);
           $content = trim(preg_replace('/[ \t]+/', ' ', $cleaned));
       }
       return [$content, $thinking !== '' ? $thinking : null];
   }

Provider-specific integration:

- **ClaudeProvider** -- Iterates response ``content``
  array. Collects ``type: "thinking"`` blocks natively,
  then runs ``extractThinkingBlocks()`` on text content.
  Merges both.
- **OpenAiProvider** -- Runs
  ``extractThinkingBlocks()`` on message content (covers
  DeepSeek, Qwen via OpenAI-compatible API).
- **GeminiProvider** -- Runs
  ``extractThinkingBlocks()`` on first candidate text
  part.
- **OpenRouterProvider** -- Inherits OpenAI behavior
  (covers all OpenRouter-hosted models).

.. _adr-016-consequences:

Consequences
============
**Positive:**

- ●● Thinking content is preserved without polluting main output.
- ● Two-tier extraction covers both native and inline thinking formats.
- ● ``hasThinking()`` convenience method for conditional UI display.
- ◐ Regex handles multiple ``<think>`` blocks per response, concatenating them.
- ◐ Content between tags is cleaned without word-gluing (space insertion).

**Negative:**

- ◑ Regex extraction adds marginal processing overhead per response.
- ◑ Non-thinking uses of ``<think>`` tags would be incorrectly extracted.

**Net Score:** +5.0 (Strong positive)

.. _adr-016-files-changed:

Files changed
=============

**Modified:**

- :file:`Classes/Domain/Model/CompletionResponse.php`
  -- Added ``thinking`` property and ``hasThinking()``.
- :file:`Classes/Provider/AbstractProvider.php` --
  Added ``extractThinkingBlocks()`` and
  ``createCompletionResponse()`` with thinking
  parameter.
- :file:`Classes/Provider/ClaudeProvider.php` -- Native
  thinking block extraction plus regex fallback.
- :file:`Classes/Provider/OpenAiProvider.php` --
  Regex-based thinking extraction.
- :file:`Classes/Provider/GeminiProvider.php` --
  Regex-based thinking extraction.
- :file:`Classes/Provider/OpenRouterProvider.php` -- Inherits OpenAI behavior.
