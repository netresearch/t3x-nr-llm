.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

================
Feature services
================

.. _feature-services:
.. _feature-services-overview:
.. _feature-services-architecture:

Feature services provide task-specific prompts and typed results over nr-llm.
Start with :ref:`integration-guide-step5` so the configuration identity and
policy remain attached to the call. Choose a recipe below, then consult its
API reference for return values and adapter limits.

- Text generation: :ref:`feature-services-completion`.
- Image input: :ref:`feature-services-vision`.
- Vectors and similarity: :ref:`feature-services-embedding`.
- Translation and separate translator results: :ref:`feature-services-translation`.

The common architecture is :ref:`architecture`. Cache hits, background runs
and direct provider calls have different behavior; they are not interchangeable
shortcuts. These recipes describe the checked main snapshot; select your
installed release and read :ref:`api-availability`.

.. _feature-services-requirements:

Requirements
============

- See :ref:`api-support-matrix` for supported TYPO3 and PHP versions.
- PHP 8.2+.
- nr-llm core extension (:php:`LlmServiceManager`).


.. toctree::
   :maxdepth: 1

   Completion
   Vision
   Embedding
   Translation
   Setup
   Testing
   Performance
   Integrations
