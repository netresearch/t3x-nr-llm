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

.. _adr-031-amendment-configuration:

Amendment (2026-08-09): a configuration selects snippets by tag
===============================================================

Until this amendment the library had no reader in a production
prompt. The only consumers were :php:`RunAugmentation::$forcedSnippets`
and the codec that rehydrates it, and :php:`RunAugmentation` is
constructed nowhere but the tool playground — so outside the playground
the whole snippet system was inert, and the "consuming extension"
of point 2 above was the only way a snippet ever reached a model.

:ref:`ADR-139 <adr-139>` names a tagged snippet as the supported way to
attach editorial context to a request. That needs a selection an
operator can make. ``tx_nrllm_configuration.snippet_tags`` is it: a CSV
of tags whose :php:`itemsProcFunc` lists the tags the snippet records
actually carry, so the vocabulary stays consumer-owned and a new
fragment kind still needs no nr_llm release.

**The selection is composed into the effective system prompt, not into
extra system messages.** :php:`ConfigurationSnippetResolver` appends the
labeled blocks to the ``system_prompt`` that
:php:`ConfigurationCallPlanner::callOptions()` has merged, behind the
configuration's own prompt. One insertion point reaches chat,
completion, streaming and the agent loop, because every
configuration-driven entry point on :php:`LlmServiceManager` builds its
options there.

The alternative — rendering each snippet as its own leading system
message, the shape the playground uses — was rejected. It only works in
the playground because the loop bakes the configuration's system prompt
ahead of the snippet messages first. Anywhere else a snippet system
message would be the first system message in the list, which is exactly
the condition under which :php:`MessageShaper::applySystemPrompt()`
leaves the list alone: the configuration's own system prompt would be
dropped from the run, silently and with no error. The characterisation
tests added with :ref:`ADR-139 <adr-139>` pin that behaviour.

Three details follow from the code:

- **Dedup is by snippet identifier.**
  :php:`PromptSnippetRepository::findActiveByTag()` loads all active
  snippets and filters in PHP, so a snippet carrying two selected tags
  comes back from both lookups and would otherwise be composed twice.
- **An unknown tag is empty, not an error** — the free-tag model has no
  referential integrity by design, so a typo degrades to "no snippets".
- **The tool playground reads the same composed value.** Its bake site
  in :php:`ToolLoopService::assemble()` goes through the same resolver,
  so a previewed transcript is the transcript a live run sends.

What this does not change: a caller-supplied system *message* still
suppresses the configuration's system prompt — and with it the snippet
block — because per-call precedence is decided before this composition
is read. That is the pre-existing rule, not a new one.

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
- Since the 2026-08-09 amendment an operator can attach snippets to a
  configuration without writing any code, and every request made with
  that configuration carries them — including requests from consuming
  extensions that know nothing about snippets.
