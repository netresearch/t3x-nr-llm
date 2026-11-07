.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-249:

=====================================================================
ADR-249: A configuration may answer a repeated request from the cache
=====================================================================

:Status: Accepted
:Date: 2026-11-03
:Amends: :ref:`ADR-005 <adr-005>`; :ref:`ADR-209 <adr-209>`
:Authors: Netresearch DTT GmbH

Context
=======

:php:`CacheMiddleware` short-circuits a call whose metadata carries a key, and
only embeddings set one. A chat or completion asked again with the same
request goes to the provider again and is paid again.

Whether that happens often enough to matter was measured before anything was
built. The telemetry row stores no prompt, so a replay over 90 days of the demo
installation counted, per caller and configuration, the successful calls whose
message count, payload bytes and token estimate equal those of an earlier call
that had finished before the later one started. Equal shape is necessary for a
hit, not sufficient, so the figures are upper bounds. Over the 133 plain chat
and completion calls that name their caller (streamed calls and agent runs
left out), 17 had such a twin within an hour and 33 within a day. Eight of the
17 are the briefing questions of nr-landingpage, whose prompt depends only on
the template.

Decision
========

**A configuration opts in with a time to live.** The field
``answer_cache_ttl`` (seconds, 0 to 86400) is 0 by default, and 0 keeps the
call exactly as before. The operator decides per configuration, because the
same request asked twice is a repeat for one caller (questions for a template)
and a request for a different answer for another (a "suggest again" button).

**Only a plain call.** :php:`LlmServiceManager::chatWithConfiguration()` and
:php:`completeWithConfiguration()` attach the entry, and every configuration
path of ``chat()``, ``complete()``, ``chatForConfiguration()`` and
``completeForConfiguration()`` goes through them. A call of an agent run, a
call that carries injected context, a tool call and a streamed answer never
get one: the first three depend on more than their messages, and the stream
does not run through the pipeline. The tool loop declines the cache on its two
tool-free calls itself, because a run whose row could not be written has no run
reference. A caller whose point is a fresh answer declines with
:php:`ChatOptions::withoutAnswerCache()`; the evaluation runner does, because a
replayed answer would grade the model as it answered before. A call bound to a
response schema gets no entry either: its caller
validates the answer afterwards, and a rejected answer must not come back for
the whole window.

**The key** is a SHA-256 over the configuration's uid, identifier and last edit
(``tstamp``), the operation, the messages after skill injection, the per-call
options, and the site and language, whose term list the guardrail applies. The user, the
budget and the correlation id stay out: they do not change the answer, so two
editors asking the same thing share one entry.

**What is stored.** Only a complete text answer: finish reason ``stop``, no tool
calls, not empty, and valid JSON when the call asked for JSON mode. :php:`CacheMiddleware` sits outside the guardrail, so the
entry is the screened answer. A replay carries no usage, is not charged against
a budget, records ``cache_hit`` in telemetry and says ``answerCache: hit`` in
its metadata. The mark :php:`CacheMiddleware::METADATA_ANSWER_CACHE`, not the
key, opts a call in, so a caller's own key on a chat call still does nothing,
and a call that carries its own key gets no answer entry.

**Privacy.** The entry is the answer verbatim. It is written only when
:php:`RunPrivacyLevelsInterface::forProvider()` answers ``full`` for the
provider that served the answer, as the provenance of :ref:`ADR-220 <adr-220>`
names it, and read only when that provider's level is still ``full``. That is
the global level tightened by the provider (:ref:`ADR-242 <adr-242>`). The
serving provider, not the configuration's, decides: a model chosen by criteria
and a fallback answer on a provider the configuration does not name. An answer
without provenance is not kept. At ``redacted``, ``metadata`` and ``none`` the
field has no effect.

**Invalidation.** The time to live; an edit of the configuration, through
``tstamp`` in the key; and flushing the caches (``nrllm_responses``, tags
``nrllm_answers`` and ``nrllm_answers_<identifier>``).

Consequences
============

- ●● A request that repeats within the window costs nothing and answers at
  once, where the operator expects repeats.
- ● Off by default and inert below ``full``: nothing changes for an
  installation that does not set it.
- ◑ The default privacy level is ``metadata``, so an installation has to raise it
  to ``full`` before the field works.
- ◑ An answer that came from a fallback is replayed as that configuration's
  answer until it expires.
- ◑ Changes to injected skill text produce a new cache key immediately.
  Changes resolved after key creation, such as referenced snippet/model
  settings, may need expiry or an explicit flush. Not every external change
  has the same invalidation behavior; see :ref:`configuration-caching`.
- ◑ The demo counts are upper bounds from one installation; the real hit rate
  shows in the ``cache_hit`` column once a configuration opts in.

Net Score: +4

Alternatives considered
=======================

**An extension-wide switch.** Rejected: it would serve a cached answer to a
button whose purpose is a different answer.

**Opt-in per call through** :php:`ChatOptions`. Rejected for now: no caller
asks for it, and it would change nothing until each extension adopts it.

**Keep a redacted copy at** ``redacted``. Rejected: a redacted copy is not the
answer, so it cannot be served.
