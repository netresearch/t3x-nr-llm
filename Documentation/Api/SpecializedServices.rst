.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _api-specialized-services:

====================
Specialized services
====================

These paths serve translation, image, speech or document operations rather
than a generic chat request. Credential lookup, pricing units and result types
can differ. See :ref:`administration-specialized-services` for configuration
and explicit quota-spending checks.

- ``Specialized\Translation\TranslatorInterface`` is an extension point for
  registered translators. It requires identity/name, static priority,
  availability, language-pair support, language discovery, single and batch
  translation, and detection. Translation returns :ref:`api-translator-result`.
  Prefer :ref:`api-translation-service` as the consumer facade.
- ``Specialized\Translation\TranslatorRegistryInterface`` supplies registered
  translators; registration does not mean usable credentials exist.
- ``Specialized\Image\ImageGeneratorInterface`` abstracts image generation
  for consumers. Match its result and options to the selected generator;
  not every generator implements every provider-specific option.
- ``Specialized\Document\PdfRasterizerInterface`` provides document
  rasterization. It needs the configured local runtime; it is not an LLM.
- ``Specialized\Speech\TextToSpeechService`` and
  ``WhisperTranscriptionService`` are public concrete services with
  ``SpeechSynthesisResult`` and ``TranscriptionResult``. This revision has
  no separate public speech-service interface to recommend.

The ``@api`` classification is per type/member; an interface's existence does
not automatically make every implementation a stable extension point. See
:ref:`api-stability`. Keep provider credentials in nr-vault and supply a
trusted budget/actor context rather than treating a caller-supplied user ID
as authentication.
