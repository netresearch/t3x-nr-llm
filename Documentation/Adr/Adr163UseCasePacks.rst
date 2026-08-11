.. _adr-163:

=========================================================
ADR-163: A use-case pack is data plus a small installer
=========================================================

:Status: Accepted
:Date: 2026-08-11
:Authors: Netresearch DTT GmbH

Context
=======

Setup starts at "Provider". That is the last question an operator can answer
and the first one the wizard asks: endpoint, adapter type, API key, then models,
then a configuration. Someone who wants help writing teasers has to translate
that want into four technical decisions before anything happens, and nothing in
the product makes the translation for them.

The pieces a working setup needs already exist separately — configuration
presets (:ref:`ADR-056 <adr-056>`), tasks, prompt snippets, tool groups,
governance profiles (:ref:`ADR-145 <adr-145>`). What is missing is a name for a
combination of them and a place to ask the earlier question.

Decision
========

**A pack is DATA.** :php:`UseCasePack` declares a configuration preset, task
records, snippet records, a recommended governance posture and the tool groups
its tasks benefit from. It has no behaviour. :php:`UseCasePackInstaller` is the
only thing that writes, it writes ordinary records through the existing
services, and it is roughly a hundred lines. A pack that could configure would
be a second configuration system beside the one it is made of.

**The configuration half IS a configuration preset.** Not a copy of one — the
same :php:`ConfigurationPreset`, published to the preset registry through
:php:`UseCasePackPresetProvider`. ADR-056 already expresses model REQUIREMENTS
rather than a chosen model, already preflights them against the installed
models, and already owns import and drift resolution. The visible consequence is
intended: a pack's configuration also appears in the Configuration module's
pending list, and importing it there installs exactly that configuration.

**A pack recommends a governance posture; it cannot apply one.**
:ref:`ADR-145 <adr-145>` decided that a profile describes and never enforces,
and this record does not reopen it. The pack names the posture its content was
written for, the plan screen renders it next to a link to the governance
readout, and installing changes no governance value. The same reasoning covers
tool groups: the pack names them, the Tools module's admin enable stays the only
way to switch one on. A pack that enabled its own tools would hand an install
button the authority of the tool gate.

**Nothing is written before an operator confirms.** :php:`plan()` is read-only
and answers what would be created, what is already there, and whether the
configuration's requirements can be met at all. The confirm is a POST; the
install action refuses a GET, so a bookmark or a prefetch cannot provision.

**"Already installed" means a record with that identifier exists.** Nothing
more. The installer never overwrites and never compares contents: an operator
who renamed a pack task or rewrote its prompt owns that record. The single
exception is the configuration's snippet-tag selection, described below — an
addition, never a replacement.
Identifier lookups run through the repositories' backend query settings, which
ignore enable fields, so a record the operator DISABLED still counts as
installed — otherwise "install again" would quietly resurrect what they switched
off.

**The configuration is the one hard requirement.** When it is neither present
nor importable, the install is refused rather than half-applied. Tasks pointing
at a configuration that does not exist fall back to the default one and would
quietly run under settings the pack never described.

**Snippet tags are derived, not declared.** A configuration composes the active
snippets carrying any of its tags (:ref:`ADR-031 <adr-031>`), so the pack takes
the union of its snippets' own tags. A separately declared tag list could name a
tag no snippet has, and the snippets a pack installs would then be read by
nothing.

**The tag link is written on every install, and it only adds.** It is the one
field the installer sets on a record it did not create, and the reason is the
bridge above: the pack's configuration can equally be created by importing its
preset in the Configuration module, and that import writes no ``snippet_tags``
— ADR-056 does not own that field. Writing the link only on the created record
would mean that whoever imported the preset first got a pack whose snippets are
installed, active, and composed into nothing, with no error anywhere. So the
installer adds the pack's tags to whatever the configuration already selects,
never removes, and re-adds nothing that is already there. What it would add is
listed on the plan screen, and a plan whose records all exist but whose tag link
is missing still offers the confirm button — that is the state that repairs it.

**A shared tag reaches other configurations, and the plan says so.** Snippets
are selected by tag, not by owner, and the vocabulary is free-form and shared:
any existing configuration that already selects ``tone_of_voice`` composes the
pack's house-style snippet the moment it is created. The installer does not
prevent that — scoping snippets to one configuration would be a second selection
mechanism beside ADR-031 — but the plan screen names every configuration the new
snippets would reach, because the operator confirms this screen and cannot
confirm an effect they were not shown.

What a pack does not contain
============================

**Skills.** A skill record carries provenance and a trust level from the source
it was synced from: a body checksum, a source SHA, an injection scan, a trust
level the trust gate reads. A pack has none of those and could only fabricate
them. A pack-installed skill would therefore be a first-party-looking record
with no source — precisely the shape the skill trust model exists to prevent.
Packs point at the Skills module instead.

**Five of the six use cases.** :php:`UseCase` names all six the plan asks about
— editorial, translation, metadata, media accessibility, agent workflows,
developer integration — because the entry step has to offer the question
whole. Only *Editorial Starter* is built. A use case with no pack says so and
links to the technical wizard rather than hiding itself, which is a more useful
answer than a question with invisible options. The others are not built; nothing
here claims otherwise.

Consequences
============

✓ An operator can start from what they want to do, see exactly which records
would be created, and get a working editorial setup in one confirmed step.

✓ Re-running an install is a no-op for everything already there, including
records the operator edited or disabled, and including a snippet tag the
configuration already selects.

◐ An install changes the prompts of existing configurations that already select
one of the pack's snippet tags. That follows from tag-based selection and is not
prevented; it is computed read-only and listed on the plan screen before the
operator confirms.

✓ No second configuration system, no second policy engine, no bypassed gate. The
pack's configuration lives in the preset lifecycle, its governance is a
recommendation, its tools go through the admin enable, and its tasks are
ordinary tasks.

◐ A pack cannot be uninstalled. Nothing marks a record as pack-owned — that is
what makes the installed records ordinary — so removing them is deleting
records, one by one, like any other. A remove flow would need ownership tracking
and would have to decide what to do with an edited record; neither is worth it
for four tasks and two snippets.

◐ A changed pack declaration updates nothing outside the configuration. The
preset drift flow covers the configuration half; a task whose declared prompt
changed after installation stays as installed. Detection is possible the same
way the preset does it (a stored checksum) and is deliberately not built: the
first question is whether operators want their edited tasks touched at all.

✕ A pack says nothing about providers or API keys. It requires capabilities and
lets the preflight report what is missing. Installing a pack on an installation
with no models still needs the technical wizard, and every screen links there.

Revisit when
============

A second pack needs something the shape does not carry — an agent-workflow pack
would want an agent definition, a media pack a speech or image configuration.
Both are additive: the pack gains a field and the installer a branch, and none
of the decisions above change.
