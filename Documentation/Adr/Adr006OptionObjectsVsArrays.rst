.. include:: /Includes.rst.txt

.. _adr-006:

===================================
ADR-006: Option Objects vs Arrays
===================================

.. _adr-006-status:

Status
======
**Superseded** by :ref:`ADR-011 <adr-011>` (2024-12)

.. _adr-006-context:

Context
=======
Method signatures like ``chat(array $messages, array $options)`` lack:

- Type safety and validation.
- IDE autocompletion.
- Documentation of available options.
- Factory methods for common configurations.

.. _adr-006-decision:

Decision
========
Introduce **Option Objects** (initially with array backwards compatibility):

.. code-block:: php
   :caption: Example: Using ChatOptions

   // Option objects only
   $options = ChatOptions::creative()
       ->withMaxTokens(2000)
       ->withSystemPrompt('Be creative');

   $response = $llmManager->chat($messages, $options);

Implementation:

- Pure object signatures: :php:`?ChatOptions`.
- Factory presets: :php:`factual()`, :php:`creative()`, :php:`json()`.
- Fluent builder pattern.
- Validation in constructors.

.. _adr-006-consequences:

Consequences
============
**Positive:**

- ● IDE autocompletion for options.
- ● Built-in validation.
- ● Convenient factory presets.
- ●● Type safety enforced.
- ● Single consistent API.

**Negative:**

- ◑ Migration required for existing code.
- ◑ No array syntax available.

**Net Score:** +5.5 (Strong positive impact - developer experience improvements with backwards compatibility)
