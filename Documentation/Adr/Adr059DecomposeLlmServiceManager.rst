.. include:: /Includes.rst.txt

.. _adr-059:

===============================================================
ADR-059: Decompose LlmServiceManager into focused collaborators
===============================================================

:Status: Accepted
:Date: 2026-07-14
:Authors: Netresearch DTT GmbH

.. _adr-059-context:

Context
=======

:php:`LlmServiceManager` had grown to 987 lines. It is the extension's central
entry point — a :php:`SingletonInterface` implementing
:php:`LlmServiceManagerInterface`, which :ref:`ADR-028 <adr-028>` classifies as
a Category-1 public API. A responsibility scan found nine distinct groups in one
class:

1. Skill-injection glue (delegates to :php:`SkillInjectionService`).
2. Default-configuration resolution.
3. Loading the ``nr_llm`` extension configuration.
4. The keyed, ExtensionConfiguration-backed provider registry (register / look
   up / list / configure providers by string identifier).
5. Generic dispatch (``chat``, ``complete``, ``embed``, ``vision``,
   ``streamChat``, ``chatWithTools``).
6. Configuration-backed dispatch (the ``*WithConfiguration`` methods).
7. The database-backed adapter factory facade.
8. Pipeline plumbing (``runThroughPipeline``, budget metadata, transient
   configuration synthesis).
9. Message shaping (system-prompt injection and message normalisation).

Two of these groups additionally carried duplicated code: the two embedding
entry points each held an inline copy of the cache-metadata block, and six
generic entry points repeated the same options / provider-key extraction
preamble.

.. _adr-059-decision:

Decision
========

Decompose the manager in stages. This ADR records **stage 1**, which extracts
the self-contained groups while leaving the dispatch logic in place. The manager
remains ``final`` and keeps implementing the unchanged
:php:`LlmServiceManagerInterface`; every former public method is retained as a
thin delegation, so the Category-1 contract, the class name, ``registerProvider``
and the three non-interface public methods (``getAdapterFromModel``,
``getAdapterFromConfiguration``, ``getAdapterRegistry``) are unchanged.

Extracted collaborators (all private, autowired via the ``Classes/*`` resource
block in :file:`Services.yaml`):

- :php:`KeyedProviderRegistry` — groups 3 + 4. Holds the mutable provider map
  and the loaded extension configuration, so it is itself a
  :php:`SingletonInterface`. ``ProviderCompilerPass`` still adds its
  ``registerProvider`` method calls to the manager service, which forward here.
- :php:`ConfigurationResolver` — group 2. ``readonly``; resolves the
  backend-managed default configuration through the repository.
- :php:`MessageShaper` — group 9. ``readonly``, stateless message normalisation
  and system-prompt injection.
- :php:`EmbedCacheKeyBuilder` — deduplicates the two inline embed cache-metadata
  blocks. The blocks are **not** identical: the ad-hoc :php:`embed()` path keys
  by provider identifier with the payload ``{input, options}`` and a
  ``nrllm_provider_<id>`` tag, while :php:`embedForConfiguration()` keys by
  configuration identifier plus the *effective* model (options override or the
  configuration's model id) with a ``nrllm_configuration_<id>`` tag, so two
  configurations pointing at different models never share entries. The builder
  therefore shares only the *structure* (the positive-ttl guard and the
  ``{cacheKey, cacheTtl, cacheTags}`` shape with the common ``nrllm_embeddings``
  tag); each caller supplies its own namespace, key payload and scope tag. This
  difference is intentional, not a defect.

The repeated options / provider-key preamble becomes a private
``splitProviderKey()`` on the manager (the six callers all remain on the manager
in stage 1).

The manager's constructor changes accordingly — it drops
:php:`ExtensionConfiguration`, :php:`LoggerInterface`,
:php:`CacheManagerInterface` and the :php:`LlmConfigurationRepository` (now
owned by the collaborators) and gains the four collaborators. The constructor is
not part of the interface contract; production wiring is autowired.

.. _adr-059-consequences:

Consequences
============

- Behaviour is unchanged. The interface signatures, the provider-resolution
  error messages and their exception codes, and the two embed cache-keying
  strategies are all preserved. The existing manager / integration / e2e tests
  continue to exercise the behaviour through the facade; they construct the
  manager through a test factory (``LlmServiceManagerTestFactory``) that accepts
  the previous leaf-dependency shape and wires the new collaborators, so the call
  sites did not each have to change shape. The extracted classes additionally get
  their own unit tests.
- New private services keep the documented public-service set stable
  (:ref:`ADR-028 <adr-028>`); the ``PublicServicesPolicyTest`` count is
  unchanged.
- The manager drops from 987 to roughly 790 lines.

Stage 2 (not in this change)
----------------------------

Groups 5 and 6 (generic and configuration-backed dispatch) plus the pipeline
plumbing (group 8) remain on the manager. Extracting per-operation dispatchers
(completion, embedding, streaming, tool-calling) is deferred to a follow-up so
this change stays reviewable and behaviour-preserving. It remains worthwhile:
after stage 1 the manager is still dominated by the dispatch methods, and those
are the natural seam for the privacy-model entry point work
(:ref:`ADR-026 <adr-026>` payload constraint). Stage 2 should be taken up when
that work needs the seam.
