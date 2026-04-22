..  include:: /Includes.rst.txt

..  _developer-fallback-chain:

=================
Fallback chain
=================

A :php:`LlmConfiguration` can carry an ordered list of other
configuration identifiers to fall back to on *retryable* provider
failures. The lookup happens transparently inside
:php:`\Netresearch\NrLlm\Service\LlmServiceManager::chatWithConfiguration()`
and :php:`completeWithConfiguration()`. Callers see a regular
completion response or a typed exception; they never need to
reach into retry mechanics.

..  _developer-fallback-chain-config:

Configuring a chain
====================

The :sql:`tx_nrllm_configuration.fallback_chain` column stores a
JSON **object** with a single key, ``configurationIdentifiers``, whose
value is the ordered array of target configuration identifiers:

..  code-block:: json
    :caption: Example payload stored in ``fallback_chain``

    {"configurationIdentifiers": ["claude-sonnet", "ollama-local"]}

Editors paste that JSON into the :guilabel:`Fallback Chain` tab in
the backend form. The order is the retry order. Identifiers are
matched case-insensitively against :sql:`tx_nrllm_configuration.identifier`.
Using an object (rather than a bare top-level array) leaves room for
future sibling fields — e.g. per-link retry policy — without a
schema break.

..  _developer-fallback-chain-retryable:

Retryable vs. non-retryable errors
==================================

Fallback only triggers for errors the next provider might actually
recover from:

..  list-table::
    :header-rows: 1
    :widths: 40 60

    * - Exception
      - Retryable?
    * - :php:`ProviderConnectionException` (network, timeout,
        HTTP 5xx, retries exhausted)
      - Yes
    * - :php:`ProviderResponseException` with code ``429``
        (rate-limited by this provider)
      - Yes
    * - :php:`ProviderResponseException` with any other 4xx
        (authentication, bad request, not found, …)
      - No. Bubbles up. A different provider with the same input
        would fail the same way.
    * - :php:`ProviderConfigurationException`
      - No. Misconfiguration is a human problem.
    * - :php:`UnsupportedFeatureException`
      - No. Fallback won't make a text-only provider handle images.

When every configuration in the chain trips a retryable error,
:php:`\Netresearch\NrLlm\Provider\Exception\FallbackChainExhaustedException`
is thrown. It carries the per-attempt errors so consumers can
surface the full failure sequence.

..  _developer-fallback-chain-scope:

Scope limits
============

v1 is deliberately narrow:

-   **No streaming.** :php:`streamChatWithConfiguration()` does not
    wrap the call. Once the first chunk has been yielded to the
    caller, mid-stream provider-switching would be detectable and
    surprising.
-   **No recursion.** A fallback configuration's own chain is
    ignored. This avoids cycles (``a -> b -> a``) and unbounded
    attempt trees.
-   **Single primary-only chain is a no-op.** If the configured
    chain contains only the primary's own identifier, the primary's
    original exception is rethrown verbatim rather than wrapped in
    :php:`FallbackChainExhaustedException`.

..  _developer-fallback-chain-example:

Using the DTO directly
======================

For programmatic construction — e.g. a wizard that generates a
configuration and also sets up fallback — use the
:php:`\Netresearch\NrLlm\Domain\DTO\FallbackChain` value object:

..  code-block:: php
    :caption: EXT:my_ext/Classes/Service/Setup.php

    use Netresearch\NrLlm\Domain\DTO\FallbackChain;

    $chain = (new FallbackChain())
        ->withLink('claude-sonnet')
        ->withLink('ollama-local');

    $configuration->setFallbackChainDTO($chain);

The DTO trims and lowercases identifiers on entry, deduplicates
them, and silently rejects empty strings and non-string entries
read from malformed JSON. See :ref:`adr-021` for the full design
rationale and the alternatives we ruled out.
