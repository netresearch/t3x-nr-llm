.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-238:

==================================================
ADR-238: A term list blocks an output and says why
==================================================

:Status: Accepted (amended; current contract below)
:Date: 2026-11-01
:Amends: :ref:`ADR-088 <adr-088>` (a stream can be held back whole),
   :ref:`ADR-233 <adr-233>` (a guardrail stop on a revision stops the translation)
:Amended: 2026-11-02 (in a run that offers tools, a list checks what a writing
   tool would store; see the amendment at the end); 2026-11-07 by :ref:`ADR-244 <adr-244>`;
   2026-11-11 by :ref:`ADR-288 <adr-288>` (in its opt-in block mode, the verdict of
   a second model on a rule set blocks, quoting the rule line and the passage)
:Authors: Netresearch DTT GmbH

.. _adr-238-current-contract:

.. note::

    Current contract (reviewed 2026-11-07): Site-aware translation and chat/tool paths can now supply site context.
    Language metadata is separate: a known site does not imply every call passes
    a language. Missing-context forbidden/required rules still apply.

    The decision text below records its original context; the linked successor
    explains the changed contract.


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
A configuration is what a call carries, and it answers "does this list apply
to that call?" by opening one record. It is the configuration the call asked
for: a held stream has to decide before a fallback is known. No configuration
on a call means no check, which includes the keyed :php:`chatWithTools()`, and
an installation without a list behaves as before.

A configuration does not separate everything, though: language detection,
quality scores and wizards run on the default configuration too. Forbidden terms
do no harm there. **A required term is only demanded from finished text**: an
answer of at least 30 words
(:php:`TermListGuardrail::MIN_WORDS_FOR_REQUIRED_TERMS`) that is no JSON, no
image description and not part of an agent run. A language code, a score, a
label or a set of wizard settings cannot hold one, and an agent run writes
through tools, so its closing note is no output. The price is that a short text
passes without the term, and so does the text an agent run writes.

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
  ``Rabatte``. A trailing ``*`` is the one way to reach further: it takes any
  letters and digits, so ``Rabatt*`` matches ``Rabatte`` and ``Rabattaktion``;
- markup does not hide a term: the text is searched as delivered and with tags
  removed, entities decoded and invisible format characters dropped.

Synonyms, misspellings and paraphrases are not found. A language model could
find them, at the price of a second call on every output and of a verdict that
cannot be reproduced; a block has to be explainable by pointing at a line in a
record.

**Where it looks.** Forbidden terms are searched in the answer and in the
string arguments of its tool calls, because that is the text a writing tool
stores. The check does not ask which tools write, so a search for a forbidden
term stops as well. The amendment at the end narrows this for a run that offers
tools. Required terms are searched in the answer only.
``thinking`` reaches no editor and is not searched.

**Site and language narrow a list; they never widen it.** A list may name one
site and one language. A caller states its own through the metadata keys
:php:`ProviderCallContext::METADATA_SITE` and
:php:`ProviderCallContext::METADATA_LANGUAGE`. No surface of this extension
states them, and no consumer does yet. For such a call the forbidden terms of a bound list apply, because a false block is
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
:php:`TranslationService` keeps its own cache of finished translations, which a
new term would otherwise never meet.

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
- ◑ A configuration a list names streams nothing until the answer is complete,
  unless the call belongs to a run that offers tools (see the amendment).
- ◑ Without a site from the caller, a list bound to one site also blocks calls
  of another site on the same configuration.
- ◑ DeepL output is not a language-model answer and is not screened. A revision
  is one, and a stop on it stops the translation: falling back would hand over
  the text no guardrail saw and hide the reason.
- ◑ The module is admin-only, like its siblings. An editor group reaches the
  records on a page with the list module, which is :ref:`ADR-169 <adr-169>`
  Option P; a module for editors would reopen the placement question ADR-169
  left to issue ``#812``.

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

Amendment (2026-11-02): in a run that offers tools, a list checks what a writing tool would store
=================================================================================================

The decision above treats a chat like a generated text. A list naming the chat's
configuration held every answer until it was complete, which took the live text
of :ref:`ADR-240 <adr-240>` away, and it stopped an editor who asked the chat to
look for a forbidden term. Neither protects a page: a chat answer is a reply to
the person who asked, and what reaches the site goes through a writing tool.

**Decision.** In a run that offers tools, the list checks the string arguments
of the tool calls that do not declare a read. Its answer is not searched, its
stream is not held, and a call of a read tool runs. A block in such a call stops
the model's response before the loop sees it, so no approval card offers the
write. The run ends with the list's message.

**What identifies such a run.** :php:`ToolLoopService` marks the reference of the
run with :php:`AgentRunReference::offeringTools()`, and
:php:`CallMetadataFactory::agentRun()` turns it into the metadata key
:php:`GuardrailMiddleware::METADATA_RUN_READ_TOOLS`. It is the property of the run
and not of a configuration: the same configuration can serve a chat and a
landing page. Being a run is not enough. A queued task and a field fill
(:ref:`ADR-234 <adr-234>`, :ref:`ADR-237 <adr-237>`) are runs with an empty
allow-list, and their answer is what gets stored, so they stay screened whole. An
editor action is a run with one tool: what it writes is an argument of that tool
and is screened, and its closing note is not.

Every other call behaves as before, with a hold and with all tool arguments
searched: a direct completion, a loop without a run such as the cowriter's, and
a run that could not be persisted.

**What counts as a read.** A tool that says so through
:php:`ToolEffectInterface`, and nothing else. :php:`ToolEffectResolver::declaresRead()`


is stricter than the default of :ref:`ADR-111 <adr-111>`, which reads a silent
tool as a read because every builtin was one. A silent tool of a third party may
store what it is given, so it is screened, and so is a name the registry does not
know. All builtins now declare their effect, and a test keeps it that way. An
imported MCP tool is screened too, a search included: it declares
``NON_IDEMPOTENT_WRITE`` as a fail-closed assumption, and its ``readOnlyHint``
stays unread for the reason :php:`ToolApprovalRule` gives.

**The list travels with the call** rather than being asked of the registry by the
guardrail. The registry builds every tool when it is constructed, and a tool of
another extension may need the language model service, which needs the
guardrails.

**The price.** A forbidden term can still appear in chat text, and an editor can
copy it into a page by hand. The list does not reach what a person types. A block
stops the whole response, so a read tool called beside the blocked write does not
run either, and the turn ends rather than letting the model try again.
