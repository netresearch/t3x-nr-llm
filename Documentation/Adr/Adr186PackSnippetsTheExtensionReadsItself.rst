.. _adr-186:

=================================================================
ADR-186: A pack may ship snippets its own extension reads
=================================================================

:Status: Accepted
:Date: 2026-09-03
:Extends: :ref:`ADR-163 <adr-163>` (what a pack declares) and
    :ref:`ADR-031 <adr-031>` (a configuration composes snippets by tag)
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-163 <adr-163>` derives a pack's snippet tags from its snippets rather
than letting the pack declare them, and states the reason plainly: a
hand-written tag list could name a tag no snippet has, "and the snippets a pack
installs would then be read by nothing". The installer therefore adds every
declared snippet's tags to the pack's configuration, and
:php:`ConfigurationSnippetResolver` composes them onto that configuration's
system prompt.

That holds for every reader ADR-163 knew about, because there was one:
:ref:`ADR-031 <adr-031>` tag composition. `nr_repurpose` is the first consumer
with a second one. Its job form offers five selectors — audience, tone of voice,
persona, layout, style — each populated from
:php:`PromptSnippetRepository::findActiveByTag()`. The editor picks specific
records, the job stores their uids, and
:php:`PromptSnippetResolver` composes exactly those, per job, for one call.

Shipping those snippets as a pack under the existing rules produces a defect
rather than a convenience. Linking ``persona``, ``layout`` and ``style`` to the
extension's text configuration makes :php:`ConfigurationSnippetResolver` append
**every active snippet carrying those tags** to the system prompt of every
completion on it — all personas at once, all layouts at once — on top of the
selection the editor made for that job. The prompt then names three speakers the
job did not choose and two mutually exclusive image sizes.

The second gap is smaller and purely mechanical. A persona carries the speaker's
voice and a layout the image dimensions, both in the record's ``metadata`` JSON
column — the shape the TCA placeholder already advertises, ``{"voice":
"nova"}``. :php:`PackSnippet` had no metadata field and
:php:`UseCasePackInstaller::buildSnippet()` wrote none, so a pack could deliver
a persona's text but not its voice.

Decision
========

**Two optional fields on** :php:`PackSnippet`, both defaulting to the behaviour
that exists today, so no declared pack changes meaning.

:php:`$metadata` is an :php:`array<string, mixed>` stored as the record's
``metadata`` JSON object. The installer stores it and interprets nothing:
whether a key means a TTS voice or an image size is the reading extension's
business, the same way the column already works for a hand-created record. It
is encoded in the constructor rather than at install time, so a declaration
that cannot be stored fails in :php:`UseCasePackRegistry`'s constructor — where
its author is — instead of on an operator's install screen. Undeclared metadata
stores ``''``, not ``{}``: :php:`PromptSnippet::getMetadataArray()` reads both
as absent, and ``''`` is what an editor's record carries, so an installed
snippet stays byte-identical to a hand-written one.

:php:`$composedByConfiguration` is a :php:`bool` defaulting to :php:`true`.
False declares a snippet the pack's own extension resolves by uid.
:php:`UseCasePack::getSnippetTags()` skips those, so the installer links no tag
for them and :php:`plan()` reports no configuration they would reach. Everything
else about them is unchanged: they are ordinary, active, editable snippet
records that appear in the Snippets module and in every tag lookup an extension
makes.

**The flag sits on the snippet, not on the pack**, because that is where the
property is: a snippet either is or is not meant to be read by tag, and a pack
is only the bundle it arrives in. The first consumer opts out for all five of
its tags — `nr_repurpose` resolves audience and tone per job exactly as it
resolves persona, layout and style — so nothing here is driven by a pack that
mixes. What the placement buys is that a later pack CAN mix without needing a
second flag, and that reading one declaration answers the question for that
record.

**"Read by nothing" is still the rule ADR-163 set** — this changes who counts as
a reader, not whether one is required. A pack that opts a snippet out is
asserting that its own extension reads it. Nothing here can verify that claim,
and nothing tries: the same is already true of a task's prompt or a recommended
tool group. What the flag buys is that the assertion is now *possible* to make,
where before the only way to ship such a snippet was to accept the tag link and
the prompt pollution that comes with it.

Consequences
============

A pack can now deliver a working persona — text, voice and all — and an
extension that composes snippets itself can ship a starter library without
poisoning the configuration it runs on.

The plan screen shows one fewer thing for such a snippet. Its row appears under
"Snippets" like any other, but it contributes no entry to the added-tags list
and pulls no other configuration into "configurations reached". That is
accurate: the install has no effect on any configuration's prompt.

An operator can still make the snippet configuration-composed afterwards, by
selecting its tag on a configuration in the Configurations module. The flag
describes the pack's intent at install time; it is not a property of the stored
record and nothing enforces it later. That is deliberate — a record the operator
owns is a record the operator can point at whatever they like.

Nothing migrates. Both fields are additive with defaults matching current
behaviour, the ``metadata`` column already exists, and packs outside this
repository compile and install unchanged.

Alternatives considered
=======================

**A flag on the pack instead of the snippet.** Smaller change, and it would
have covered the first consumer, which opts out for everything it ships. It was
refused because the property belongs to the snippet: a pack that ships a
house-style snippet next to a set of personas is an ordinary thing to want, and
an all-or-nothing switch would force one of the two into the wrong mode with no
way back except splitting the pack in half.

**Making** :php:`$configurationPreset` **nullable**, so a snippet-only pack
needs no configuration at all. This is the bigger, more honest-looking change
and it was refused: it removes the preflight that proves the pack can actually
run, it touches :php:`plan()`, :php:`install()`, the backend module and its
templates, and it answers a question nobody asked — the repurpose pack *does*
have a configuration (``nr_repurpose_text``), and installing it is useful.

**Letting the extension create the records itself**, in an install hook or an
upgrade wizard. That is the state this replaces in every consumer that has ever
needed seeded records, and ADR-163 already rejected it for tasks and
configurations: it puts a second writer next to the installer, with no plan
screen, no idempotency contract and no place for the operator to say no.
