.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _administration-snippets:

========================
Managing prompt snippets
========================

Prompt snippets are small named prompt *fragments* —
personas, tones of voice, target audiences, image
styles, layouts — that editors manage centrally.
Consuming extensions (for example ``nr_repurpose``)
query snippets by tag and compose them into their
prompts.

Snippets are deliberately **not** prompt templates:
a :ref:`prompt template <adr-031>` is a complete,
versioned prompt with model parameters, while a
snippet is a reusable building block without any
model binding.

.. _administration-snippets-add:

Adding a snippet
================

1. Navigate to :guilabel:`AI > Authoring >
   Snippets`.
2. Click :guilabel:`New Snippet`.
3. Fill in the fields:

   :guilabel:`Identifier`
      Unique technical identifier (e.g.,
      ``persona-friendly-expert``).

   :guilabel:`Name`
      Display name (e.g., ``Friendly Expert``).

   :guilabel:`Tags`
      Comma-separated tags consuming extensions
      search for (see below).

   :guilabel:`Snippet text`
      The prompt fragment itself.

   :guilabel:`Metadata (JSON)`
      Optional JSON object with extra settings.

4. Click :guilabel:`Save`.

.. _administration-snippets-tags:

Tag convention
==============

Tags are free-form, comma-separated strings. There
is no fixed vocabulary — consuming extensions agree
on tags with the editors. Matching is exact per tag
and case-insensitive: the tag ``style`` does *not*
match a snippet tagged ``lifestyle``.

Established tags so far:

==================  =============================================
Tag                 Used for
==================  =============================================
``audience``        Target audience descriptions
``tone_of_voice``   Tone-of-voice instructions
``persona``         Writing/speaking personas
``layout``          Layout instructions (e.g. for slides)
``style``           Image / visual style descriptions
==================  =============================================

Persona snippets may carry a voice hint in their
metadata so speech features can pick a matching
text-to-speech voice:

..  code-block:: json
    :caption: Metadata of a persona snippet

    {"voice": "nova"}

.. _administration-snippets-configuration:

Attaching snippets to a configuration
=====================================

A configuration can select snippets by tag. Every
request made with that configuration then carries
them — chat, single-prompt completion, streaming and
agent runs alike — without any extension code.

1. Navigate to :guilabel:`AI > Setup >
   Configurations` and edit a configuration.
2. Open the :guilabel:`Parameters` tab.
3. Tick the wanted tags under :guilabel:`Prompt
   snippet tags`. The list offers the tags the
   snippet records actually carry.
4. Click :guilabel:`Save`.

The active snippets carrying any ticked tag are
appended to the configuration's
:guilabel:`System Prompt`, each as a ``NAME:`` block
separated by a blank line, in the order the tags are
listed. A snippet carrying two ticked tags is added
once. A tag no snippet carries adds nothing — there
is no error, matching the free-tag model above.

..  code-block:: text
    :caption: Effective system prompt of a configuration with the tags ``persona`` and ``tone_of_voice``

    You are a helpful assistant.

    Nova persona:
    You are Nova, a friendly expert.

    Formal tone:
    Use a formal, professional tone of voice.

Two limits are worth knowing:

- Only **active** snippets are composed; hiding a
  snippet removes it from every configuration that
  selects its tag.
- A caller that supplies its own system message
  replaces the configuration's system prompt for
  that call, and with it the snippet block. This is
  the documented per-call precedence and predates
  this field. The exception is described below.

.. _administration-snippets-caller-system:

Snippets in a caller's system message
=====================================

The chat and other callers send their own system
message, so a configuration's snippets do not reach
them. An administrator can let a snippet through
(:ref:`ADR-214 <adr-214>`, item 8). Both conditions
must hold:

1. **The snippet's block is marked.** In
   :guilabel:`AI > Authoring > Snippets`, click the
   check-mark button of the snippet. The page shows
   the block exactly as it is appended — the name as
   a label, then the text — and the snippet's tags,
   and :guilabel:`Mark this block` marks the block
   with those tags. Any later change to the name, the
   text or the tags takes the snippet out until it is
   marked again, so an editor who may edit snippets
   can neither route new text into the chat's system
   message nor route a marked snippet to another
   configuration. A block with
   characters a browser does not show cannot be
   marked; the page lists them.
2. **The configuration opts in.** Tick
   :guilabel:`Marked snippets in the caller's system
   message` on the :guilabel:`Parameters` tab. Only
   administrators see and write the field, and the
   configuration's snippet tags, whatever a group is
   granted; the mark is an administrator's too.

The marked blocks of the snippets the configuration
selects by tag are then appended to the caller's
first system message, in the order above — only to
a system message at the head of the conversation,
before the first user turn. Without such a message
nothing changes: the configuration's system prompt
carries the snippets as before.

A run started with forced snippets (the playground, a
queued run) follows the same rule: when the caller
sent its own system message, only marked snippets
reach it, each once — forced snippets included — and
the configuration's prompt is not added.

Unmarking or editing a snippet takes it out of every
run composed afterwards; a run that waits for a
human keeps the transcript it was composed with.

.. _administration-snippets-developer:

Using snippets from an extension
================================

Query snippets by tag through the public
:php:`PromptSnippetRepository` and compose the
selected fragments with the
:php:`PromptSnippetComposer`:

..  code-block:: php
    :caption: Composing snippets into a prompt

    $audiences = $this->promptSnippetRepository
        ->findActiveByTag('audience');
    $tones = $this->promptSnippetRepository
        ->findActiveByTag('tone_of_voice');

    $sections = $this->promptSnippetComposer->composeSections([
        'TARGET AUDIENCE' => $audiences[0] ?? null,
        'TONE OF VOICE' => $tones[0] ?? null,
    ]);

:php:`composeSections()` renders each non-null
snippet as a ``LABEL:`` block followed by the
snippet text, joined by blank lines. Null entries
and empty snippets are skipped.

:php:`findActiveByTag()` filters on ``is_active``
only — like every repository here it ignores the
enable fields, so **hidden** records are part of
its result. Configurations drop them when they
compose their prompt; an extension that queries
directly has to skip :php:`isHidden()` snippets
itself if it wants the same behaviour.

See :ref:`ADR-031 <adr-031>` for the design
rationale.
