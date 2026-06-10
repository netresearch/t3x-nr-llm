.. include:: /Includes.rst.txt

.. _adr-031:

==================================================================
ADR-031: Tagged Prompt Snippet Library
==================================================================

:Status: Accepted
:Date: 2026-06-10
:Authors: Netresearch DTT GmbH

.. _adr-031-context:

Context
=======

Consuming extensions — first ``nr_repurpose`` — assemble prompts from
recurring building blocks: a persona, a tone of voice, a target
audience, an image style, a layout instruction. Editors want to manage
these fragments centrally, once, instead of re-typing them into every
extension's own configuration.

The existing :php:`PromptTemplate` entity does not fit this need. It is
a heavyweight *complete* prompt: it binds a feature, carries model
parameters (temperature, max tokens, top-p), supports versioning with
parent/variant relations, and tracks usage performance. A persona like
"You are Nova, a friendly expert." has none of these concerns — it is a
fragment that only becomes a prompt when a consumer composes it with
its own instructions. Forcing fragments into :php:`PromptTemplate`
would either bloat every fragment record with irrelevant model fields
or fork the template semantics depending on a "fragment" flag.

A second question is how consumers select fragments. A fixed category
enum (like :php:`Task` categories) would require an nr-llm release
every time a consuming extension introduces a new fragment kind, which
contradicts the goal of nr-llm being a shared foundation that consumers
extend without touching it.

.. _adr-031-decision:

Decision
========

Introduce a separate, lightweight :php:`PromptSnippet` entity
(table ``tx_nrllm_promptsnippet``) next to — not on top of —
:php:`PromptTemplate`:

1. **Fragments, not templates.** A snippet is identifier + name +
   description + fragment text. No model parameters, no versioning, no
   performance tracking. :php:`PromptTemplate` stays untouched.

2. **Free-form CSV tags instead of a category enum.** Snippets carry a
   comma-separated ``tags`` field. Consumers query
   :php:`PromptSnippetRepository::findActiveByTag()`, which matches
   tags as exact, case-insensitive tokens — ``style`` never matches
   ``lifestyle``. The tag vocabulary is a *convention* between editors
   and consumers (established so far: ``audience``, ``tone_of_voice``,
   ``persona``, ``layout``, ``style``), documented in the TCA field
   description and the administration guide. New fragment kinds need no
   nr-llm release.

3. **JSON metadata side-channel.** An optional ``metadata`` JSON object
   carries consumer-specific settings (e.g. ``{"voice": "nova"}`` on
   persona snippets so speech features can pick a matching TTS voice).
   :php:`getMetadataArray()` returns ``[]`` for empty or invalid JSON —
   bad editor input must never break a consumer.

4. **Composition stays in nr-llm.** :php:`PromptSnippetComposer`
   renders an ordered label-to-snippet map into labeled prompt blocks
   (``LABEL:`` + fragment text, blank-line separated), so all consumers
   produce uniformly structured prompt sections.

5. **Editing via FormEngine.** The backend module gets a "Snippets"
   list following the established Providers/Models/Tasks pattern;
   create/edit links into FormEngine, no custom forms.

.. _adr-031-consequences:

Consequences
============

- Editors manage personas, tones, audiences, styles, and layouts once,
  centrally; every consuming extension reads the same library.
- The free-tag model keeps nr-llm release-independent from consumer
  vocabulary — at the cost of no referential integrity: a typo in a tag
  silently yields an empty query result. The documented convention and
  the tag badges in the list view mitigate this.
- Token matching is implemented over the CSV field in PHP, not SQL
  ``LIKE``, guaranteeing exact-token semantics on every database
  platform. The snippet library is small (tens of records), so loading
  active snippets for tag filtering is not a performance concern.
- Two prompt-related entities now coexist. The split is intentional
  (template = complete prompt, snippet = fragment) and documented here,
  in the administration guide, and in both entities' PHPDoc.
