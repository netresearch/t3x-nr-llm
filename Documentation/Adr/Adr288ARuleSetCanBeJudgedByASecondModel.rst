.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-288:

====================================================
ADR-288: A rule set can be judged by a second model
====================================================

:Status: Accepted
:Date: 2026-11-11
:Amends: :ref:`ADR-243 <adr-243>` (a prose rule can be checked),
   :ref:`ADR-238 <adr-238>` (in the opt-in block mode a model's verdict blocks)
:Amended: 2026-11-11 by :ref:`ADR-290 <adr-290>` (a call that names further
   configurations is judged by each of their judges; the strictest mode decides)
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-243 <adr-243>` made a rule set a tagged snippet for the context and a
term list for the check, and left a rule such as "address the reader formally"
unchecked. :ref:`ADR-238 <adr-238>` had rejected a language model as the
checker: it costs a second call per output, its verdict cannot be reproduced,
and a block has to be explainable by pointing at a line in a record.

On 2026-11-11 the product owner accepted ADR-243 and asked for more: an optional
check of the prose rules by a small, fast and cheap model, which an
administrator sets to report or to block.

Decision
========

**A configuration opts in, and the mode has three values.** ``off`` is the
default. ``report`` delivers the text with a hint per broken rule. ``block``
stops the text like a term list. The switch, the rule set and the judging model
sit on the configuration, in the fields ``rule_judge_mode``,
``rule_judge_tags`` and ``rule_judge_configuration``, which only an
administrator writes: the judge sends the output to another provider, the
same reason ``snippet_tags`` is admin-only (ADR-214 item 8). Each field carries
the plain display condition ``HIDE_FOR_NON_ADMINS``; only that form makes the
DataHandler refuse a non-admin's write, inside an ``AND`` it would only hide
the field.

**The rule set is the snippets the configuration names by tag, line by line.**
The tags are the snippet tags of :ref:`ADR-031 <adr-031>`. Every non-empty line
of an active, visible snippet carrying one of them is one numbered rule. A line
is the unit because it is what a hint quotes and what the author of the snippet
can point at.

**The judging model is a configuration, chosen explicitly.** nr-llm has no model
tiers, so there is no "small model" to default to. A configuration brings the
provider, the model, the timeout, the budget and the fallback chain, and the
call goes through the completion service, so usage, cost, telemetry, the budget
and the output guardrails of that configuration apply.

**One call per text, bounded.** The judge asks once, with temperature 0, JSON
mode and a schema, and does not repair: the structured completion would ask a
second time. The timeout is the judging configuration's, capped at 20 seconds
(:php:`RuleJudge::MAX_TIMEOUT_SECONDS`).

**Only a finished text is judged**: no tool round of a plain loop, no image
description, nothing under 30 words. A JSON answer is judged by its strings, as
that is the text a wizard places on a page. A run without tools, a queued task
or a field fill, stores its answer, so that answer is judged.

**In a run that offers tools, the write is the output**, the boundary of the
ADR-238 amendment. The chat and an editor action write through their tools, and
their answer is conversation. The judge reads the string arguments of the tool
calls that do not declare a read, once per response that proposes them. In
report mode the write is proposed with hints; in block mode the response is
stopped and the message says the change was not proposed. The judge runs after every other guardrail, so it reads a
redacted text, and a text a term list stopped costs no call.

**The text is data.** The system prompt says so, and the text sits between two
markers carrying a random value, so it cannot end its own section and add an
instruction.

**A verdict is checked before anyone sees it.** The answer has to match the
schema. A violation counts when its rule number exists and its passage occurs
in the text, white space and case aside. When the judge names violations and
none can be found, the answer is unusable: the model saw something it could not
point at.

**The judge does not reach a less trusted provider.** When the judging
configuration's trust zone (:ref:`ADR-094 <adr-094>`) is less trusted than the
zone the text was written in, or does not admit the declared class of a rule
snippet, the check does not run. The judging side counts every provider it can
reach, fallbacks included. The judged side is the provider that served the call
where the response names it (:php:`GeneratorProvenance`), else the
configuration's own provider. Where neither is known, as for a configuration
that picks its model by criteria, or the provider declares no zone, the text
counts as written in the most trusted zone, ``local``, so only a judge there is
asked. The usual default for an unknown zone, the least trusted one, is right
for where data may go and would let any judge pass here.

**Report.** A hint names the rule, quotes its line and the passage. A failed
check, which is a timeout, an error, an unusable answer or a missing judging
configuration, delivers the text with a hint that it was not checked.

**Block.** This amends ADR-238 for this mode: a model's verdict stops an
output. The block stays explainable as ADR-238 requires, because the message
quotes the rule line from the snippet record and the passage, and both are
verified against the record and the text before the message is written. The
governance log stores the rules and not the passage
(:php:`GuardrailResult::$auditReason`), so it keeps no generated text
(:ref:`ADR-064 <adr-064>`).

**A stored refusal holds no passage.** The block message quotes generated text
for the person looking at the screen. A consumer that keeps the refusal in a
record, a job's error or a chat message, keeps it beyond the request and shows
it to people who did not run the call, whatever the privacy level of the run
(:ref:`ADR-064 <adr-064>` decides what the extension's own run records keep, not
a consumer's tables). So :php:`GuardrailViolationException::storableMessage()`
gives the refusal without the passage, the same text the governance log keeps,
and a consumer stores that.

**A failed check blocks in block mode.** The text under review can confuse the
judge into an answer that cannot be used. If that let the text through, an
output could switch off its own check. ADR-238 decided the same for a term list
that cannot be read. Where availability matters more than the rule, the mode to
choose is ``report``.

**A stream.** Block mode holds the stream, as a term list does. Report mode
streams live and judges the whole text at its end. The hints are the return
value of the stream's generator, through the new
:php:`StreamAnnotatingGuardrailInterface`.

**How a hint reaches a caller.** :php:`GuardrailResult::allowWithHints()` lets
a guardrail pass an output with findings. :php:`GuardrailMiddleware` writes them
into ``CompletionResponse::$metadata['guardrailHints']``, and
:php:`GuardrailHint::fromMetadata()` and :php:`GuardrailHint::fromStreamReturn()`
read them back. A caller that gets no response object, a string, a decoded
array or the result of a tool loop or an agent run, wraps its call in
:php:`GuardrailHintCollector::capture()`, a public shared service the output
middleware and the streaming dispatcher record into. All additions are additive
to the ``@api`` surface (:ref:`ADR-127 <adr-127>`).

**Where an editor sees a hint.** The AI task module and the tool playground of
this extension; the cowriter's writing dialog, the landing page wizard, the
repurpose job view and the AI chat follow in their own repositories.

Consequences
============

- ●● A prose rule can be checked, and the editor reads which rule and which
  passage.
- ● Off by default: an installation that does nothing pays nothing.
- ◐ One more paid call per finished text on an opted-in configuration, also
  for a cached answer, which is screened again when it is replayed
  (:ref:`ADR-249 <adr-249>`).
- ◐ A verdict of a model is not reproducible. Temperature 0 narrows it, and the
  passage check keeps an invented finding out; a missed one stays possible.
- ◑ Block mode stops writing on its configuration while the judging model is
  down.
- ◑ A caller has to read the metadata, the stream's return value or a
  capture to see a hint; a block reaches every caller as the usual
  :php:`GuardrailPolicyException`.
- ◑ In a run that offers tools, only what a writing tool would store is
  judged; the chat's text is free, as for a term list.
- ◑ A held stream in block mode shows no text growing, as with a term list.

Net Score: +3

Alternatives considered
=======================

**Report only.** Rejected by the product owner: some rules, legal phrasing
first, are meant to stop a text.

**Fail-open in block mode.** Rejected for the reason above: an output that
could switch off its own check has not passed.

**The structured completion with its repair round.** Rejected: up to two calls
per text, against the bound of one.

**A model tier on the model record.** Rejected for now: it would be a new
concept for one consumer, and a configuration already says which model,
provider and timeout to use.
