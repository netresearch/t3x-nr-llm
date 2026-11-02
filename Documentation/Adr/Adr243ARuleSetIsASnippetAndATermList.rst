.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-243:

====================================================================
ADR-243: A rule set is a snippet and a term list
====================================================================

:Status: Accepted
:Date: 2026-11-02
:Authors: Netresearch DTT GmbH

Context
=======

An audit of the stack asked for a central record of brand, legal and design rules
that the cowriter, nr-landingpage and nr-repurpose use as context and can be
checked against. :ref:`ADR-139 <adr-139>` deferred a context provider registry
until a consumer has a source that can be expressed neither as a skill nor as a
tagged snippet. :ref:`ADR-214 <adr-214-d8>` item 8 settled how a snippet reaches a
caller that sends its own system message.

Decision
========

**No new record. A rule set is a tagged snippet for the context and a term list
for the check.** Both exist, and the trigger of ADR-139 has not fired: rules for a
brand are text, which a snippet holds.

- The prose goes into a prompt snippet with a tag the configurations of these
  surfaces select. Without a system message of the caller it joins the
  configuration's prompt. With one, as a surface with its own prompt sends, it
  joins when an administrator marked the snippet and the configuration opted in
  (ADR-214 item 8).
- What a machine can check goes into a term list on the same configurations
  (:ref:`ADR-238 <adr-238>`): forbidden and required terms, with a message that can
  point at the rule.

A required term is not asked of an agent run. A prose rule is not checked. ADR-238 rejected a language model as the checker,
because a block has to be explainable by pointing at a line in a record, and a
rule set does not change that.

Consequences
============

- ● One place for the prose and one for the words, both already editable, marked
  and reported on.
- ◑ A rule such as "address the reader formally" is context and no check.
- ◑ A surface that sends its own system message needs the mark and the opt-in, or
  the rule does not reach it.

Net Score: +2

Alternatives considered
=======================

**A rule set record with sections.** Rejected: it would be a third home for the
text of a snippet, with its own approval path and no consumer it serves better.
