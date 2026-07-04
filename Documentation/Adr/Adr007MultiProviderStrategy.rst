.. include:: /Includes.rst.txt

.. _adr-007:

==================================
ADR-007: Multi-Provider Strategy
==================================

.. _adr-007-status:

Status
======
**Accepted** (2024-01)

.. _adr-007-context:

Context
=======
Supporting multiple providers requires:

- Dynamic provider registration.
- Priority-based selection.
- Configuration per provider.
- Fallback mechanisms.

.. _adr-007-decision:

Decision
========
Use **tagged service collection** with priority:

.. code-block:: yaml
   :caption: Configuration/Services.yaml

   # Services.yaml
   Netresearch\NrLlm\Provider\OpenAiProvider:
     tags:
       - name: nr_llm.provider
         priority: 100

   Netresearch\NrLlm\Provider\ClaudeProvider:
     tags:
       - name: nr_llm.provider
         priority: 90

.. note::

   The shipped providers no longer carry an explicit ``tags:`` entry — they
   self-register via the :php:`#[AsLlmProvider]` attribute collected by
   :php:`ProviderCompilerPass` (:ref:`ADR-022 <adr-022>`). The ``tags:`` form
   above still works for third-party providers.

Provider selection:

1. Explicit provider in the per-call options.
2. Otherwise the active DB-backed default configuration's provider.
3. Otherwise :php:`getProvider(null)` **throws** a :php:`ProviderException`.

There is deliberately **no** "first provider by priority" fallback: the
implicit default-provider fallback was removed in :ref:`ADR-034 <adr-034>`, so
provider selection is always explicit (per-call option or the active
configuration).

.. _adr-007-consequences:

Consequences
============
**Positive:**

- ● Easy provider registration.
- ● Clear priority system.
- ●● Supports custom providers.
- ● Automatic fallback.

**Negative:**

- ◑ Priority conflicts possible.
- ◑ All providers instantiated.
- ◑ Configuration complexity.

**Net Score:** +5.5 (Strong positive impact - flexible
multi-provider support with minor overhead)
