.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-300:

======================================================
ADR-300: A delivered text is checked as it is delivered
======================================================

:Status: Accepted
:Date: 2026-11-15
:Amends: :ref:`ADR-288 <adr-288>` (its egress rule now has one implementation for two callers)
:Authors: Netresearch DTT GmbH

Context
=======

An editorial result, such as a social post or a reply to a comment, is used
outside TYPO3. The rules for it already exist: term lists
(:ref:`ADR-238 <adr-238>`), the rule-set judge (:ref:`ADR-288 <adr-288>`) and
the term lists of further configurations (:ref:`ADR-290 <adr-290>`). All three
run inside the guardrail pipeline, which has to decide for every provider call
what the call's text is. Its answers fit the backend and do not fit an
editorial result:

- A run that offers tools writes through them, so its answer is conversation
  and only written tool arguments are checked. A run that only reads is not
  checked at all.
- The judge skips a text under 30 words, and a required term is asked of no
  text under 30 words. Those limits keep the pipeline off alternative texts,
  labels and codes, and they are right there.
- A required term is asked of no agent run and never of JSON.
- A text the editor changed outside nr-llm can only be checked by generating
  it again.

The guardrail cannot drop its limits for one kind of caller, because it does
not know which caller it serves. The caller does.

Decision
========

A service, ``ExternalTextCheck``, checks one given text against a configuration
and the further configurations a caller names. The caller says that the text is
an editorial result. The service applies the same rules as the pipeline, with
no word limit:

- **Terms.** Every forbidden term found and every required term missing, across
  every term list that applies to the site and language given. The text is
  searched as it is. When it is one JSON document (a code fence around it
  allowed), ``json_decode()`` decodes it once and every key and every string
  value is searched too; nothing here lexes JSON. A document that names a key
  twice, or a key only some decoders accept, cannot be vouched for, because
  consumers differ in which member they keep: the term check fails and the
  text blocks. A text that is no JSON is plain text: an escape in it is not
  decoded. A list is found by the configuration it names, as in the pipeline, so
  a further configuration that is hidden still brings its lists. Its judge
  applies exactly when the pipeline's would: both look the configuration up by
  uid in the same way. The report names all of them; the pipeline stops at the
  first.
- **Judge.** Every configuration whose judge is on is judged with its own rule
  set by its own judging configuration. The strictest mode decides. The judge
  reads the text as it is delivered, a JSON text with its keys and every
  duplicate key included; the pipeline judges only the strings of a JSON
  answer, because a wizard places those strings.
- **Egress.** The judge may not reach a provider less trusted than the one that
  wrote the text, nor one whose zone does not admit a rule snippet's data class.
  This rule moves from the guardrail into ``JudgeEgressGate``, which both use,
  so they cannot drift apart. An unknown writer counts as the most trusted zone,
  as before, and so does a writer that is named and no longer exists. A
  passage the judge quotes counts when it is found in the text or in a
  decoded string of a JSON document.

The report has one of four states:

- ``passed``: checked against at least one rule, and none broken;
- ``violation``: a term or a rule is broken;
- ``not_checked``: a part of the check did not run, in report mode, or no rule
  applies to the text at all;
- ``failed``: a part did not run and a judge is in block mode, or the term
  lists could not be read.

A text no rule applies to is ``not_checked``, never ``passed``: a pass says
that rules were applied. A broken term blocks delivery, as it does in the
pipeline; a broken rule blocks under a judge in block mode. A part of the check
that blocks when it cannot run, the term lists or a judge in block mode, is
recorded as a failed check on its own, apart from the status: a finding that
does not block, such as a rule broken under a report-mode judge, can never hide
it. An empty text is not judged. The service does not throw: a store error
fails the check.

The report is bound to the text by its SHA-256 digest, and carries a digest of
the rule state: per configuration its judge settings and snippet tags, the text
of its selected snippets and rule snippets, and its term lists. Snippets a
single run forces on top of its configuration are not part of it. The judging
configuration is named apart from the configuration whose text was checked.

The pipeline's limits do not change; they keep protecting the calls that are
not editorial results. The one change to the pipeline is in the shared egress
rule: a serving provider a response names that no longer exists now counts as
the most trusted zone, where it fell back to the configuration's provider.

Consequences
============

- A short text, a JSON text and the answer of a run that only read can be
  checked, as soon as a caller asks for it. The end-of-run check
  (ADR-302) is that caller; the report artifact
  (ADR-301) carries the result.
- A report states what it checked against: the digest is a fingerprint of the
  rule state read just before the check, not a copy, and the check reads the
  rules live again. An edit between those two reads can make the check apply
  a newer rule than the digest names; a different digest always means
  something changed.
- A judge call costs a model call. A caller that checks a text the pipeline
  already judged pays twice unless it switches the pipeline's judge off for that
  call; ADR-302 does so.
- A channel limit in characters is not part of the check: no profile field holds
  one yet.

Revisit when
============

- A channel needs a character limit or another format rule per profile.
- Paused runs must keep the rule texts they started with, not only report that
  they changed.

Alternatives considered
=======================

- **Lower the limits of the guardrail for agent runs.** Rejected: the limits
  protect alternative texts, labels and codes, which run on the same
  configurations. The pipeline cannot tell an editorial result from a label.
- **Copy the egress rule into the new service.** Rejected: two copies of a
  security rule drift. The rule moved into one class both callers use.
- **Report ``passed`` when no rule applies.** Rejected: a pass would then say
  nothing, and a missing rule set would read as a clean text.
