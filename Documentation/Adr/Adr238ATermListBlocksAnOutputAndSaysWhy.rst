.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-238:

====================================================================
ADR-238: A term list blocks an output and says why
====================================================================

:Status: Accepted
:Date: 2026-11-01
:Amends: :ref:`ADR-088 <adr-088>` (a stream can be held back whole)
:Authors: Netresearch DTT GmbH

Context
=======

Nothing lets an editorial team say "this word must not appear" or "this word
must appear". :ref:`ADR-085 <adr-085>` built the place for it: a guardrail can
deny an output. Three things stood in the way.

:php:`GuardrailInterface::checkOutput()` sees the response and nothing else, so
it cannot know which configuration or site a call belongs to. A stream is only
audited after delivery (:ref:`ADR-088 <adr-088>`). A denial is a typed
exception, and almost every surface turns it into a generic failure: the task
module, the cowriter dialog, the landing page wizard and the repurpose job all
hide the reason, and two landing page paths drop the result without a word.

Decision
========

**A term list is a record, ``tx_nrllm_term_list``.** It holds forbidden terms
and required terms, one per line, in text fields for the reasons
:ref:`ADR-208 <adr-208>` gives for the glossary. The module
:guilabel:`AI > Authoring > Term Lists` lists them; FormEngine edits them.

**A list names the configurations it applies to, and must name at least one.**
A guardrail with no scope would run on everything that goes through the
pipeline: language detection, document analysis, typed decisions and field
fills, where a forbidden word in a source document is no reason to stop.
A required term would fail all of them. A configuration is the one thing
every call that matters already carries, and it answers "does this list apply
to that call?" by opening one record. No configuration on a call means no
check, and an installation without a list behaves as before.

**The guardrail is mandatory in the sense of** :ref:`ADR-106 <adr-106>`.
Otherwise a configuration with an ``allowed_guardrails`` selection would
silently skip a list that names it. The list's own scope is the only place
that decides.

**The check.** :php:`TermMatcher` is the whole contract, and the manual repeats
it:

- case is ignored; ``ß`` and ``ss`` stay different letters;
- a term matches whole words: neither side may touch a letter, a digit or an
  underscore, so ``Kunde`` does not match ``Kundenkonto``;
- a space in a term matches any run of white space;
- there is no inflection and no stemming. ``Rabatt`` does not match
  ``Rabatte``. A trailing ``*`` is the one way to reach further: ``Rabatt*``
  matches ``Rabatte`` and ``Rabattaktion``;
- markup does not hide a term: the text is searched as delivered and with tags
  removed, entities decoded and invisible format characters dropped.

Synonyms, misspellings and paraphrases are not found. A language model could
find them, at the price of a second call on every output and of a verdict that
cannot be reproduced; a block has to be explainable by pointing at a line in a
record.

**Where it looks.** Forbidden terms are searched in the answer and in the
string arguments of its tool calls, because that is the text a writing tool
stores. Required terms are searched in the answer only, and not at all on a
step that carries tool calls or has no text: such a step is not the output.
``thinking`` reaches no editor and is not searched.

**Site and language narrow a list; they never widen it.** A list may name one
site and one language. A caller states its own through the metadata keys
:php:`ProviderCallContext::METADATA_SITE` and
:php:`ProviderCallContext::METADATA_LANGUAGE`. Most callers cannot state them
today. For such
a call the forbidden terms of a bound list apply, because a false block is
visible and has a reason, and a false pass is neither. Its required terms do
not apply, because demanding one site's required term from a call that may
belong to another site would be a false block with no way out.

**A block names the terms and the list, never the text.** The message is
written in the language of the acting backend user and followed by the list's
own message. It is what the editor reads, and it is what
:php:`GovernanceEvent::$detail` stores, so it holds policy facts and no
generated content (:ref:`ADR-064 <adr-064>`).

**A failed check blocks.** When the lists cannot be read or the pattern engine
gives up, the guardrail denies with a message that says the check could not
run. An output nobody could check is not an output that passed.

The missing table is the exception. Between a code update and the database
schema update, the table does not exist, and every language-model call of the
installation would stop for a list that cannot exist yet. A missing table
cannot hold a rule, so the output passes and a warning is logged.

**A stream a list applies to is held back.** This amends
:ref:`ADR-088 <adr-088>`, which only audits what it has already sent. A
guardrail that implements :php:`ContextualGuardrailInterface` can ask for it
through ``holdsStream()``. :php:`StreamingDispatcher` then collects the
redacted text, screens it as a whole and yields it as one chunk, so a blocked
stream delivers nothing. Any other stream is delivered as before, and an
installation without a list never holds one. A term could have been checked
in a sliding window, but a required term is only known to be missing at the
end, and a half-written answer the editor has already read cannot be taken
back. The price is that the editor sees no text growing on these
configurations.

**The extension point is an additional interface.**
:php:`ContextualGuardrailInterface` extends :php:`GuardrailInterface` with
``checkOutputInContext()`` and ``holdsStream()``. The middleware and the
dispatcher call the first where it exists. :php:`GuardrailInterface` itself
gains nothing, as :ref:`ADR-127 <adr-127>` requires.

**The cached translations are flushed** when a list is saved, as for a glossary.
:php:`CacheMiddleware` sits outside the guardrail, so a cached answer is never
screened again.

**A surface shows the reason.** :php:`GuardrailPolicyException` is the type to
catch; its message is written for the person who asked. The surfaces of this
extension that end a task or an editor action now do so. The consumers follow
in their own repositories.

Consequences
============

- ●● An editor can stop a word, and reads which word and which list did it.
- ● A block is one more governance event, and the widget and the run detail
  page show it without a change.
- ◐ One query per screened response. A cache would have to be invalidated on
  every save, against a call that takes seconds.
- ◑ A configuration a list names streams nothing until the answer is complete.
- ◑ Without a site from the caller, a list bound to one site also blocks calls
  of another site on the same configuration.
- ◑ DeepL output is not a language-model answer and is not screened.
- ◑ The module is admin-only, like its siblings. An editor group reaches the
  records on a page with the list module, which is :ref:`ADR-169 <adr-169>`
  Option P; a module for editors would reopen the placement question ADR-169
  left to issue ``#812``.
- ◑ Text in structured output is searched as the JSON the model sent. A term
  written with ``\u`` escapes is not found.

Net Score: +3

Alternatives considered
=======================

**A list that applies to everything unless it names configurations.** Rejected
for the reason above: the default would stop pipelines that publish nothing.

**A language model as the checker.** Rejected as the first step. See the
contract above. The rule set (:ref:`ADR-139 <adr-139>` names snippets as the
home of such text) is the place for prose rules.

**Redacting a forbidden term instead of blocking.** Rejected: a text with a hole
in it reads as finished, and the editor would have to find the hole.

**The sliding window of** :ref:`ADR-088 <adr-088>` **for forbidden terms.**
Rejected: it cannot serve required terms, and two mechanisms for one list is
one too many.
