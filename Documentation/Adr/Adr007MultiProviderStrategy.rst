.. include:: /Includes.rst.txt

.. _adr-007:

==================================
ADR-007: Multi-Provider Strategy
==================================

Status
======
**Accepted** (2024-01)

Context
=======
Supporting multiple providers requires:

- Dynamic provider registration
- Priority-based selection
- Configuration per provider
- Fallback mechanisms

Decision
========
Use **tagged service collection** with priority:

.. code-block:: yaml

   # Services.yaml
   Netresearch\NrLlm\Provider\OpenAiProvider:
     tags:
       - name: nr_llm.provider
         priority: 100

   Netresearch\NrLlm\Provider\ClaudeProvider:
     tags:
       - name: nr_llm.provider
         priority: 90

Provider selection hierarchy:

1. Explicit provider in options
2. Default provider from configuration
3. First configured provider by priority
4. Throw exception if none available

Consequences
============
**Positive:**

- ● Easy provider registration
- ● Clear priority system
- ●● Supports custom providers
- ● Automatic fallback

**Negative:**

- ◑ Priority conflicts possible
- ◑ All providers instantiated
- ◑ Configuration complexity

**Net Score:** +5.5 (Strong positive impact - flexible multi-provider support with minor overhead)
