.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

..  _administration-specialized-services:

============================================
Verifying the specialized services
============================================

Translation, image generation and speech are not configured as records. Each
one reads a nr-vault identifier from the Extension Configuration, and until a
consuming extension calls it, nothing tells you whether that identifier
resolves to a working credential.

The test page — :guilabel:`LLM > Overview > Test` — answers that directly for
translation and image generation.

..  _administration-specialized-services-keys:

Which setting belongs to which service
======================================

..  list-table::
    :header-rows: 1

    * - Setting
      - Used by
    * - ``translators.deepl.apiKeyIdentifier``
      - DeepL translation
    * - ``image.fal.apiKeyIdentifier``
      - FAL image generation
    * - ``providers.openai.apiKeyIdentifier``
      - DALL·E images, Whisper transcription and text-to-speech — all three
        share this one identifier and cannot be configured separately

..  _administration-specialized-services-translation:

Translation
===========

Enter a text and a target language. Leaving the source language empty makes the
service detect it.

The translator picker lists every registered translator and marks the ones
without a usable credential. Leaving it on :guilabel:`LLM (default)` runs the
LLM path, which needs a working provider but no specialized key — useful to
confirm the page itself works before pointing at DeepL.

Selecting a translator routes to that one, which is the case worth running
after entering its vault identifier. The result names the translator that
answered, the detected source language, and either the characters billed
(specialized translators) or the tokens used (the LLM path).

The test page names no site, so no site glossary applies to its translations;
see :ref:`administration-glossaries` for when one does.

..  _administration-specialized-services-deepl-quota:

DeepL character quota
---------------------

Above the form, the :guilabel:`DeepL quota` line shows the characters used
and the character limit of the current billing period, the share already used,
and which API answered: :guilabel:`DeepL API Free` for a key ending in
``:fx``, :guilabel:`DeepL API Pro` otherwise, :guilabel:`custom endpoint` when
``translators.deepl.baseUrl`` points elsewhere. It is read from DeepL's usage
endpoint when the page opens and again after each DeepL translation; asking it
costs no characters (:ref:`ADR-207 <adr-207>`).

When no DeepL key is configured, or DeepL cannot be reached, the line says so
and names the Extension Configuration. When DeepL refuses the key, it says the
key was rejected. The key itself is never part of the answer.

..  _administration-specialized-services-image:

Image generation
================

Enter a prompt and pick :guilabel:`OpenAI (DALL·E)` or :guilabel:`FAL`. Each
service applies its own default model and size; the result reports which were
used, and DALL·E additionally reports the prompt it rewrote yours into.

The OpenAI service takes its default model from the model registry: the active
model record with the :guilabel:`Image Generation` capability that is flagged as default,
otherwise the one sorted first, skipping model ids it cannot send to OpenAI.
Without such a record it uses ``gpt-image-2``. OpenAI shut down ``dall-e-2``
and ``dall-e-3`` on 2026-05-12 and ``gpt-image-1`` on 2026-10-23, so an active
record for one of those models makes the test fail; deactivate it or point it
at a current model.

..  note::

    The generated image is **not stored**. It is rendered from what the
    provider returned — a URL for FAL, an inline data URI for OpenAI — and is
    gone when you reload. Putting an image into the file storage, attaching it
    to a record or reusing it is the job of the extension that consumes
    nr_llm; this page only proves the credential works.

..  _administration-specialized-services-errors:

Reading the result
==================

A missing or unresolvable credential is reported as such, naming the Extension
Configuration — that is the failure this page exists to surface. A request the
provider refused with a client error (HTTP 4xx), such as a model it no longer
offers, is reported as a refused request with its status code: the credential
works, the request did not. Any other failure of an otherwise-configured
service is reported generically. In every case the provider's own message is
written to the system log only, so that provider responses never reach the
browser.

All endpoints are admin-only. The translation and image tests spend real
provider quota on every run; the DeepL quota line does not.

..  _administration-specialized-services-speech:

Speech
======

Transcription and text-to-speech have no test surface. Transcription needs an
audio upload and synthesis returns binary audio, which raises the same storage
question the image test declines to answer, with less to gain from answering
it. Their credential is the shared OpenAI identifier above, so a successful
DALL·E test also confirms the key those two use.
