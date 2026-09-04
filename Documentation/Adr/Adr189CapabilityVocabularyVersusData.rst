.. include:: /Includes.rst.txt

.. _adr-189:

=================================================================
ADR-189: A model capability is vocabulary, and only sometimes data
=================================================================

:Status: Accepted
:Date: 2026-09-04
:Extends: :ref:`ADR-018 <adr-018>` (multi-provider model discovery) and
    :ref:`ADR-138 <adr-138>` (criteria-mode selection matches the operation,
    not only the criteria)
:Authors: Netresearch DTT GmbH

Context
=======

:php:`ModelCapability` offers eleven cases. Two of them are written by no
model discoverer at all, and until this record nothing said so anywhere a
reader would look: the enum carried one line of prose that named three
examples, and the distinction that matters was buried in the class docblock of
:php:`OperationCapabilityMap`, a class nobody consults when authoring criteria.

The three surfaces disagree by construction and each is right on its own terms:

- The **enum** is the vocabulary a record may use. It is deliberately wider
  than any one provider, because it also has to describe what an administrator
  ticks by hand and what the specialized services do.
- **Discovery** writes only what a provider's own response substantiates, which
  :ref:`ADR-018 <adr-018>` requires and which #671/#676 made true per
  discoverer. The result is correct data and UNEVEN data: Groq's listing has no
  capability field, so its models are seeded ``chat`` alone, and a Groq record
  without ``tools`` is a gap rather than a statement.
- **Criteria** may require any of the eleven.

The failure follows from the three together.
:php:`EligibilityEvaluator::matchesCapabilities()` requires every capability a
criteria set lists, so one entry nothing writes makes the whole set
unsatisfiable — and it reports "no candidates", which reads as "this
installation has no suitable model" rather than "this requirement can never be
met anywhere".

That is not hypothetical. ``nr_repurpose_text`` required ``json_mode``
alongside ``chat``. The use-case pack carrying that preset could not be
installed on any installation, and the demo instance reported it on every
deploy until the criterion was dropped. The message named both capabilities, so
a reader looked first at ``chat``, which every model there declares (#913,
#918).

Decision
========

**A capability may be required in selection criteria only if some producer
actually writes it.** The enum stays the wider vocabulary; the constraint is on
what criteria may demand, not on what the enum may contain.

Three consequences, in the order they bind:

1. **The enum documents, per case, who writes it and who reads it.** Not a
   description of the feature — every reader can guess what ``vision`` means.
   What they cannot guess is that ``completion`` means the same thing as
   ``chat`` in this codebase, or that ``json_mode`` is forwarded to providers
   without any capability ever being consulted.

2. **The claim is measured, not read off the sources.**
   :php:`CapabilitySeedTest` runs every discoverer — each one's offline corpus
   plus the recorded responses — and collects what they write into
   :php:`DiscoveredModel::$capabilities`. A case written by none of them must
   appear in that test's exception list with a reason, or the build fails. Its
   predecessor scanned the discovery sources for the quoted token and could not
   tell an assignment from an array subscript: it read OpenRouter's
   ``$pricing['completion']`` as evidence that ``completion`` was seeded, and
   therefore stayed silent about a dead capability.

3. **When criteria cannot be satisfied, the message names the capability, not
   the set.** The preset preflight narrows one capability at a time and reports
   the ones no active model declares (#918).

Enforcement of an operation's own required capability is a separate axis and
stays where :php:`OperationCapabilityMap` documents it: enforced only for
``chat``, ``vision`` and ``tools``, because those are the tokens whose
producers have caught up, and ``observe`` remains the safer default for an
upgrade.

Consequences
============

**A dead capability now fails a build rather than an operator's install.** The
exception list is the place where "nothing writes this" is stated on purpose,
and a reviewer sees it in the diff when it grows.

**The list is not a promise about every possible provider response.** The test
proves what the recorded responses and the offline corpora produce. A mapper
branch added without a recording that reaches it stays invisible to the check,
which is why each entry carries a reason a reader can verify rather than a bare
token.

**The two remaining unseeded cases stay in the enum, for different reasons.**
``audio`` was the third and left the list the same day this record was drafted:
:php:`OpenAiModelDiscoverer` now seeds it from the model id, the instrument it
already used for ``tts-``, ``whisper-`` and ``gpt-image``, because
``GET /v1/models`` describes no capabilities. Nothing on the call path branches
on it, so a wrong assignment widens selection rather than breaking a call.

- ``json_mode`` is a request option, not something a model can be asked to do.
  :php:`ChatOptions` carries ``responseFormat`` to the provider
  unconditionally and no call path consults the capability, so as a criterion
  it can only subtract models. Populating it would assert per model that the
  provider honours ``response_format``, which fails at call time when it does
  not. Removing the case belongs to the 1.0 API freeze (#895), because it and
  :php:`Model::supportsJsonMode()` both sit on the frozen surface.
- ``completion`` is a synonym of ``chat`` here.
  :php:`CompletionService::complete()` delegates to
  :php:`LlmServiceManager::chat()` and every adapter posts to
  ``chat/completions``; no legacy completions endpoint is called anywhere.
  Seeding it would give chat models a second token with the same meaning, so
  two criteria sets differing only in which one they name would select
  differently for no reason. It was found by the replacement check, because
  the one it replaced counted OpenRouter's pricing keys as seeds.

**Nothing changes at runtime.** No capability is added, removed or newly
enforced by this record; it names a rule the code already followed unevenly and
makes the next violation visible.
