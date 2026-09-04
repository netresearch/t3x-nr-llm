.. include:: /Includes.rst.txt

.. _adr-188:

============================================================================
ADR-188: Every conversation has a configuration from the moment it opens
============================================================================

:Status: Accepted
:Date: 2026-09-04
:Authors: Netresearch DTT GmbH

.. _adr-188-context:

Context
=======

:ref:`ADR-151 <adr-151>` bound a conversation to the configuration it was
opened with, and :php:`ConversationService` says so in its own class docblock:
"the turn runs against the configuration the session was opened with, resolved
fresh each time so a deactivated or newly restricted configuration stops the
conversation instead of silently continuing on the installation default".

The binding is real, and it has a hole exactly one turn wide.
:php:`startSession()` accepts a null configuration and writes an **empty**
``configuration_identifier``. Everything that follows then reads that emptiness
as "no binding":

- :php:`resolveTurnConfiguration()` returns null,
- so the context-window fit of :ref:`ADR-121 <adr-121>` is skipped entirely —
  turn 1 of that conversation is budgeted against nothing,
- so :php:`dispatch()` falls through to the generic :php:`chat()`, where
  :php:`LlmServiceManager` resolves the installation default **itself**,
- and the skill block of that configuration is injected by the manager into a
  transcript this service has already sized without it. The
  :php:`skillBlockFor()` docblock records that as a known limit: "the manager
  resolves the installation default itself and injects that configuration's
  skills — this service never learns which configuration that is, so it cannot
  account for its block."

So the conversation does run against a configuration; it simply runs against
one nobody wrote down, chosen one layer lower, one turn later, and re-chosen on
every turn. Change the installation default halfway through and the same
conversation continues on another model, another budget and another guardrail
set — the outcome ADR-151's binding exists to prevent, reachable by not passing
an argument.

.. _adr-188-decision:

Decision
========

**A session is bound when it is opened, or it is not opened.**

- :php:`startSession()` ends with a concrete configuration: the caller's if one
  was given, otherwise the installation default resolved **at that moment**, and
  the identifier is persisted with the row.
- Omitting the argument therefore no longer means "unbound". It means "the
  installation default, as it stands now" — a defaulting rule, not an absence.
- When neither resolves, the session is **refused** with
  :php:`ConfigurationNotFoundException`. No row is written. The alternative is a
  row with an empty identifier, which is the state this record exists to remove,
  and the caller would learn about the missing default one turn later from a
  different error anyway.
- A session opened before this rule binds itself **once**, on its next turn, and
  keeps running unbound if the installation still has no usable default. An
  improvement that cannot be made is not a reason to end a conversation someone
  is in the middle of.

.. _adr-188-no-wizard:

Why no upgrade wizard
=====================

A wizard would write the installation default **as it stands when the wizard
runs** into every unbound session. For a conversation nobody ever continues that
is a decision made for nothing; for one that is continued it is the same
decision the next turn makes anyway — one turn earlier and, decisively, without
the actor. A restricted default would then be guessed at rather than evaluated.

Binding on the next turn has the actor in hand, so
:php:`ConfigurationResolver::resolveDefaultForActor()` can evaluate the
restriction instead of refusing outright the way its actor-less sibling
:php:`resolveDefaultConfiguration()` must.

The write is conditional on the row still being unbound —
``WHERE uid = ? AND configuration_identifier = ''`` — so two concurrent turns of
the same legacy session cannot re-point a conversation the first one already
bound. The database decides, not the ordering of two reads.

.. _adr-188-consequences:

Consequences
============

- **Behaviour change on a public method.** :php:`startSession()` now throws
  where it previously succeeded, on an installation with no usable default. Its
  signature is unchanged, so the frozen surface (:ref:`ADR-127 <adr-127>`) does
  not move; the CHANGELOG announces the behaviour.
- **The generic path is gone from conversations.** Every turn of every session
  now dispatches through :php:`chatForConfiguration()`. The fit runs on turn 1,
  and the skill block it budgets for is the one that is actually injected —
  closing the limit :php:`skillBlockFor()` documents.
- **A session can now fail on its second turn where it previously drifted.** A
  conversation bound to a configuration that is later deactivated stops, which
  is ADR-151's intent; before, an unbound one silently moved to whatever the
  default had become. The louder behaviour is the correct one.
- :php:`ConfigurationResolver` gains :php:`resolveDefaultForActor()`. It is the
  actor-aware sibling of :php:`resolveDefaultConfiguration()`, and the two
  differ in exactly one case: a group-restricted default, refused there because
  there is nobody to check, evaluated here because there is.
