.. include:: /Includes.rst.txt

.. _adr-034:

======================================================================
ADR-034: Remove the ExtensionConfiguration default-provider fallback
======================================================================

:Status: Accepted
:Date: 2026-06-24
:Authors: Netresearch DTT GmbH

.. _adr-034-context:

Context
=======

:php:`LlmServiceManager` carried a session-level *default provider*: a
nullable ``defaultProvider`` string seeded from
``ExtensionConfiguration['nr_llm']['defaultProvider']`` and mutable at
runtime through :php:`setDefaultProvider()` / :php:`getDefaultProvider()`
(both on the public :php:`LlmServiceManagerInterface`). When a generic
:php:`chat()` / :php:`complete()` / :php:`streamChat()` call pinned no
provider, :php:`getProvider(null)` fell back to that string.

This is a remnant of the original provider-centric design that predates
the database-backed three-tier model (:ref:`adr-013`,
:ref:`adr-001`). Since :ref:`adr-021` / :ref:`adr-026`, the generic
entry points resolve the **active default** ``tx_nrllm_configuration``
record first (``isActive = 1 AND isDefault = 1``, via
:php:`LlmConfigurationRepository::findDefault()`); the
``ExtensionConfiguration`` fallback was only ever reached when no such
record existed.

In practice the fallback was inert: the ``defaultProvider`` key was
never exposed in ``ext_conf_template.txt``, so it was always ``null`` in
production unless an integrator set it by hand in ``additional.php``. It
was also misleading — together with the orphaned ``plugin.tx_nrllm``
TypoScript (removed in `#255
<https://github.com/netresearch/t3x-nr-llm/pull/255>`__, answering
`discussion #254
<https://github.com/netresearch/t3x-nr-llm/discussions/254>`__) it
suggested a second, config-driven way to choose a provider that no code
path honoured as the source of truth.

.. _adr-034-decision:

Decision
========

Remove the default-provider concept from :php:`LlmServiceManager`
entirely. The database is the single source of truth for provider
selection.

1. **Drop the state and its seed.** The ``defaultProvider`` property and
   the ``ExtensionConfiguration['nr_llm']['defaultProvider']`` read in
   :php:`loadConfiguration()` are removed. The rest of the extension
   configuration (provider-specific settings consumed by
   :php:`registerProvider()`) is unaffected.

2. **Remove the public accessors.** :php:`setDefaultProvider()` and
   :php:`getDefaultProvider()` are removed from
   :php:`LlmServiceManagerInterface` and its implementation. **This is a
   breaking change** to the public service contract.

3. **`getProvider(null)` throws.** With no fallback,
   :php:`getProvider()` requires an explicit identifier; called with
   ``null`` it throws :php:`ProviderException` (code ``4867297358``)
   with guidance to configure a default Configuration in the backend
   module. The signature keeps the nullable parameter for callers that
   pass a possibly-null pinned provider.

.. _adr-034-consequences:

Consequences
============

- ● One way to choose a provider: pin it per call (the ``provider``
  option on :php:`ChatOptions` / :php:`EmbeddingOptions`) or let the
  generic path resolve the active default Configuration. No silent,
  inert third path.
- ● The :php:`LlmServiceManagerInterface` shrinks by two methods that
  no production code consumed.
- ◐ **Breaking:** integrators that called
  :php:`setDefaultProvider()` / :php:`getDefaultProvider()`, or relied
  on the ``defaultProvider`` extension-config key, must instead create
  an active+default Configuration record or pin the provider per call.
  No production deployment used the key (it was never exposed in
  ``ext_conf_template.txt``), so real-world impact is expected to be
  nil.
- ● No production behaviour change in practice: the generic entry
  points already resolved the database default first, and the fallback
  was never populated in production.
- ◐ Supersedes the provider-default resolution steps of :ref:`adr-007`
  ("Default provider from configuration" / "First configured provider by
  priority"): provider selection is now per-call or via the active default
  Configuration only, with no extension-config or priority fallback.
