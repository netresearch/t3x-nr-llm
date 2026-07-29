.. include:: /Includes.rst.txt

.. _adr-121:

============================================================================
ADR-121: Conversations are bounded where they are assembled
============================================================================

:Status: Accepted
:Date: 2026-07-29
:Authors: Netresearch DTT GmbH

.. _adr-121-context:

Context
=======

The agent loop bounds its transcript against the model's context window
(:ref:`adr-107`). Conversations do not. :php:`ConversationService::send()`
replays the whole persisted history on every turn — system prompt, every stored
message, then the new one — so a long conversation grows until the provider
refuses it. The user gets an error instead of an answer, and gets it abruptly:
the turn before worked.

.. _adr-121-decision:

Decision
========

Bound the transcript in :php:`ConversationService::send()`, using the existing
:php:`ContextWindowManager`. The configuration for the turn is resolved before
the transcript is assembled, because the bound depends on the model the request
will actually go to.

**Not in the shared completion path.** The obvious-looking alternative was to
put the fit into the terminal that every configuration-driven call passes
through, so conversations and everything else would be covered at once. That
would have broken working callers. Document analysis, translation and plain
completion all funnel through it, and they send a single large message. The
manager drops whole older turns; with one turn there is nothing to drop, so it
reports that the request does not fit and the caller throws. Those calls work
today. A conversation is the only path that grows across turns, and it is the
only path bounded here.

**No re-fit on fallback.** The roadmap offered planning against the smallest
model in the fallback chain, or re-fitting when a fallback fires. The second
cannot work as the code stands: a context overflow is not classified as
retryable, so the fallback chain is never walked for it. The first was rejected
as too costly for the benefit — it would shrink every conversation to the
smallest model that *might* serve it, including the overwhelming majority of
turns that never leave the primary.

**When even the floor does not fit, send it anyway.** The manager keeps the
system prompt, the opening exchange and the newest turn; if that still exceeds
the budget it says so. The request is sent regardless, and a warning is logged.
The estimate deliberately errs high, so this often succeeds. When it does not,
the provider's own error is exactly what the caller would have received before.
Refusing here would end a conversation the provider might still have answered.

.. _adr-121-visibility:

A trim is recorded, not just logged
===================================

Trimming means the model answers without part of the history. A reader who
cannot see that will misread the answer.

The number of dropped turns is persisted on the **user** row of the turn, in
``tx_nrllm_ai_session_message.dropped_turns``. The user row is written before
the provider call, so the fact survives a failed call; the assistant row only
exists on success. ``NULL`` means no fit was evaluated — a row from before this
change, or a session without a bound configuration. ``0`` means the fit ran and
kept everything.

The stored history is never shortened. It is the audit record, and it stays
complete; the column records what the model *saw*, which is a different fact.

A log line alone was not enough. The agent loop can rely on one because a run
also carries a termination reason and an inspector view; a conversation has
neither.

The count is not written into a governance event. That table's decisions are
denials and gates, and the dashboards render them as blocks. A routine, working
degradation does not belong in a chart operators read as "things that were
refused".

.. _adr-121-consequences:

Consequences
============

A long conversation now shortens instead of failing. The oldest exchanges are
the ones the model stops seeing, which is the least surprising thing to lose.

Nothing reads ``dropped_turns`` yet — there is no conversation UI in the
backend. It is written because the fact cannot be reconstructed afterwards,
while a reader can be added whenever one is needed.

Two conversation shapes are still unbounded, and both are follow-ups rather than
oversights: a session opened without a bound configuration (there is no model to
measure against, so the manager has no window to fit), and a turn whose options
pin a provider directly rather than naming a configuration.

.. _adr-121-estimator:

On the token estimate
=====================

The estimator counts UTF-8 **bytes**, not characters, and this was reviewed and
kept. Bytes per token stay roughly comparable across scripts while characters
per token do not: Latin text runs about one byte per character, CJK about three,
and tokens track the byte count far more closely than the character count.
Dividing bytes by 3.5 lands within a modest margin for both. Counting characters
instead would under-estimate CJK by roughly a factor of three — the direction
that fails at the provider — so the apparently obvious fix would have been a
significant regression.
