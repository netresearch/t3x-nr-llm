.. include:: /Includes.rst.txt

.. _adr-006:

===================================
ADR-006: Option Objects vs Arrays
===================================

Status
======
**Superseded** by :ref:`ADR-011 <adr-011>` (2024-12)

Context
=======
Method signatures like ``chat(array $messages, array $options)`` lack:

- Type safety and validation
- IDE autocompletion
- Documentation of available options
- Factory methods for common configurations

Decision
========
Introduce **Option Objects** (initially with array backwards compatibility):

.. code-block:: php

   // Option objects only
   $options = ChatOptions::creative()
       ->withMaxTokens(2000)
       ->withSystemPrompt('Be creative');

   $response = $llmManager->chat($messages, $options);

Implementation:

- Pure object signatures: ``?ChatOptions``
- Factory presets: ``factual()``, ``creative()``, ``json()``
- Fluent builder pattern
- Validation in constructors

Consequences
============
**Positive:**

- ● IDE autocompletion for options
- ● Built-in validation
- ● Convenient factory presets
- ●● Type safety enforced
- ● Single consistent API

**Negative:**

- ◑ Migration required for existing code
- ◑ No array syntax available

**Net Score:** +5.5 (Strong positive impact - developer experience improvements with backwards compatibility)
