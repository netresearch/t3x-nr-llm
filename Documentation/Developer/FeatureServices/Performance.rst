.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

==========================
Caching and batch behavior
==========================

.. _feature-services-performance:

Performance
===========

.. _feature-services-caching:

Caching
-------

- **Embeddings**: 24h cache by default; ``EmbeddingOptions::noCache()`` opts out.
- **Plain completion/chat**: configuration ``answer_cache_ttl`` opts in; off
  by default and subject to privacy and path restrictions.
- **Vision**: separate from the plain-answer cache path.
- **Translation**: The translator path opts in with a positive
  ``TranslationOptions::cacheTtl`` and needs the cache service. LLM translation
  and polishing also need a known model/configuration context for their cache
  key; other translators do not require a default LLM configuration. See
  :ref:`api-translation-options`.

.. _feature-services-batch:

Batch processing
----------------

Image arrays provide a convenient batch interface. Each image makes its own
provider request, in order; batching does not reduce the number of requests.

.. code-block:: php
   :caption: Batch processing example

   // One provider request per image, with results in the same order
   $altTexts = $visionService->generateAltText($imageUrls);
