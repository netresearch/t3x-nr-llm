.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _administration-rule-set-judge:

==============
Rule-set judge
==============

A term list checks words. A rule such as "address the reader formally" is prose,
and a term list cannot check it. The rule-set judge lets a second, small model
read each finished text of a configuration and check it against the rules in
the snippet library. It is off unless an administrator switches it on.
:ref:`ADR-288 <adr-288>` records the decisions.

.. _administration-rule-set-judge-setup:

Switching it on
===============

#. Write the rules into one or more prompt snippets and give them a tag, for
   example ``brand_rules``. Every non-empty line is one rule, so write one rule
   per line. A hint quotes the line.
#. Create a configuration for the judge: a small, fast model with a short
   timeout, for example 10 seconds, and no fallback chain, so that one check
   stays one call. A timeout is not retried. Its provider must be at least as
   trusted as the provider that wrote the text, and must admit the data class
   declared on the rule snippets. Otherwise the check does not run. Where the
   writing provider is not known, for a configuration that picks its model by
   criteria or a provider without a declared trust zone, only a judge on a
   ``local`` provider is asked.
#. Open the configuration whose texts are to be checked, tab
   :guilabel:`Rule-set judge`:

   :guilabel:`Check against the rule set`
       :guilabel:`Off`, :guilabel:`Report` or :guilabel:`Block`.

   :guilabel:`Rule set (snippet tags)`
       The tags of the rule snippets. The same tags can also be selected under
       :guilabel:`Prompt snippet tags`, so the model writing the text sees the rules too.

   :guilabel:`Judging configuration`
       The configuration created in step 2. There is no default: nr-llm does
       not know which of your models is the small one.

Only administrators see and write the fields, also through the DataHandler,
whatever a group is granted. The judge sends the text to the
provider of the judging configuration, which is the same kind of decision as
routing snippets into a caller's system message.

.. _administration-rule-set-judge-modes:

Report and block
================

:guilabel:`Report`
    The text is delivered. For each broken rule the editor reads a hint that
    quotes the rule and the passage. When the check fails, the text is delivered
    with a hint that it was not checked.

:guilabel:`Block`
    A broken rule stops the text like a term list. The message quotes the rule
    and the passage. When the check fails, the text is stopped as well: an
    output must not be able to switch off its own check by confusing the judge.
    The block appears as a governance event, which names the rules but does not
    store the passage.

A check fails when the judging configuration is missing or inactive, the call
fails or takes longer than its timeout (20 seconds at most), the answer does
not match the expected format, or the judge names violations of which none can
be found in the text. A failure is logged as a warning.

.. _administration-rule-set-judge-scope:

What is checked
===============

One call per finished text. Not checked are answers under 30 words, the tool
rounds of a plain loop and image descriptions. A JSON answer, as a wizard
receives it, is checked by its text values. A queued task and a field fill have
no tools and store their answer, so that answer is checked.

In the AI chat and in editor actions, runs that offer tools, the change a
writing tool would make is checked, not the text of the chat: one call per
response that proposes a write. In report mode the change is proposed with the
hints; in block mode it is not proposed, and the chat says which rule stopped it.

A stream is held in block mode, so the editor sees the text appear at once after
the check. In report mode it streams as usual and is checked at its end.

The judge runs after the other guardrails. A text a term list already stopped
costs no call.

A caller can name further configurations whose guardrails apply too
(:ref:`ADR-290 <adr-290>`); the AI chat does so when it runs on a site's own
configuration and names its own. Each of them with the judge switched on is
checked with its own rule set by its own judging configuration, one call each,
and the strictest mode decides: block over report over off. The same rule
broken at the same passage is reported once.

.. _administration-rule-set-judge-cost:

Cost and limits
===============

Every check is a call on the judging configuration and is recorded like any
other call: in usage, cost, telemetry and the budget of that configuration. A
cached answer is checked again when it is served.

The judge is a model. Its verdict can differ between two runs on the same text,
and it can miss a violation. A hint or block only quotes a rule that exists and
a passage that is in the text, so it cannot point at something invented.

.. _administration-rule-set-judge-callers:

Where editors see the result
============================

A block reaches every surface that shows term-list messages today, as the same
kind of exception. Its message quotes the passage; a surface that stores the
refusal, a job's error or a chat message, stores the version without it.

Hints appear in the AI task module and the tool playground, and with their
releases in the cowriter's writing dialog, the landing page wizard, the
repurpose job view and the AI chat.

For developers: a hint travels with the response, in
``CompletionResponse::$metadata`` under ``guardrailHints`` (read with
:php:`GuardrailHint::fromMetadata()`), and for a stream as the return value of
the generator (:php:`GuardrailHint::fromStreamReturn()`, once drained). A call
that returns a string, an array, a tool loop or an agent run is wrapped in
:php:`GuardrailHintCollector::capture()`, which returns the result and the hints
reported during it. To store a refusal, use
:php:`GuardrailViolationException::storableMessage()`.
