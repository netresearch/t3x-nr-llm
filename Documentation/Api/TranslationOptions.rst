.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _api-translation-options:

===================
Translation options
===================

Options apply differently to the LLM and translator paths; see
:ref:`api-translation-service`.

- ``formality``: 'formal', 'informal', 'default'
- ``domain``: 'technical', 'legal', 'medical',
  'marketing', 'general'
- ``glossary``: array of term translations
- ``site``: site identifier (``withSite()``). When
  no ``glossary`` is set, the glossary that site
  keeps for the language pair applies — in the
  prompt on the LLM paths, as a DeepL glossary on
  the DeepL path (:ref:`ADR-208 <adr-208>`,
  :ref:`administration-glossaries`)
- ``preserve_formatting``: bool
- ``provider``, ``model``: pin the provider /
  model for this call
- ``configuration``: identifier of a stored
  ``LlmConfiguration`` whose translator is used on
  the specialized-translator path
  (``translateWithTranslator()``)
- ``tag_handling``: ``html`` or ``xml``
  (``withTagHandling()``). DeepL keeps the tags
  and translates the text between them; the LLM
  translator keeps tags while
  ``preserve_formatting`` is on
  (:ref:`ADR-209 <adr-209>`)
- ``cache_ttl``: seconds (``withCacheTtl()``).
  Opt-in cache for ``translateWithTranslator()``:
  an identical request — translator, languages,
  text, glossary, options, and for the LLM
  translator the default configuration with its
  skills (no provider pinned) or the provider's
  default model (provider pinned, no model) — is
  answered from the ``nrllm_responses`` cache (tag
  ``TranslationService::CACHE_TAG``) without
  reaching the translator, and therefore without a
  budget check or a usage row. Blank and truncated
  answers are never stored; saving or deleting a
  glossary, configuration, model, provider, skill
  or prompt snippet flushes the tag. Off by default
  (:ref:`ADR-209 <adr-209>`)
- ``max_tokens``: on the configuration path capped
  at the model's ``max_output_tokens`` when known
  (:ref:`ADR-209 <adr-209>`)
- ``polish`` (``withPolish(bool $enabled = true,
  ?string $instruction = null)``): after a
  translator other than the LLM one, e.g. DeepL,
  ``translateWithTranslator()`` and
  ``translateBatchWithTranslator()`` let a language
  model revise the text for fluency, terminology and
  tone, on the default configuration or the pinned
  provider and model. Tags, placeholders and
  glossary terms must survive; when they do not, or
  the call fails or the budget refuses it, the first
  translation is returned. A guardrail stop is
  thrown instead. ``isPolished()`` on the
  result says whether the revision applied,
  ``metadata['polish']['reason']`` why not. The
  revision is recorded as a ``translation_polish``
  row with the characters of the first translation
  sent for revision, written whether or not the
  answer is accepted. The revision is an ordinary
  request. An uncached translation with a revision normally uses two
  requests. User limits cover both calls; each configuration's limits apply
  only to calls made through that configuration. Exhausting the translator's
  configuration limit does not itself block a revision using another
  configuration. A budget refusal of the revision returns the first
  translation. A configuration named with ``withConfiguration()``
  selects the translator only; it does not apply to
  the revision. The instruction goes into the system
  message: it must come from the calling code, never
  from editor input. Off by default
  (:ref:`ADR-233 <adr-233>`)
