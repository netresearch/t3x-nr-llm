.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-290:

=============================================================
ADR-290: A caller adds the term lists of another configuration
=============================================================

:Status: Accepted
:Date: 2026-11-11
:Amends: :ref:`ADR-238 <adr-238>`, :ref:`ADR-244 <adr-244>`, :ref:`ADR-288 <adr-288>`
:Authors: Netresearch DTT GmbH

Context
=======

A term list applies to the calls of the configurations it names
(:ref:`ADR-238 <adr-238>`). A site can name its own configuration
(:ref:`ADR-244 <adr-244>`), and the backend AI chat (nr-mcp-agent ADR-033)
now runs a conversation about a page of such a site on the site's
configuration instead of the configuration of its Task. The lists an
administrator bound to the chat's configuration then stopped applying on
exactly the sites that set their own: the replacement dropped bans.

The owner's decision of 2026-11-11: when the chat runs on a site's
configuration, the term lists of both configurations apply. A ban can be
added, never dropped, as a privacy level can only be tightened. The rule-set
judge (ADR-288) follows the same principle.

Decision
========

**A caller names further configurations.**
``AbstractOptions::withGuardrailConfigurations(int ...$uids)`` lists the uids
of configurations whose term lists screen the call besides the lists of the
configuration it runs on. Uids of 0 or below and repeats are dropped. The
value travels like the site: as the metadata key
``ProviderCallContext::METADATA_GUARDRAIL_CONFIGURATIONS`` (comma-separated
uids) on every call ``CallMetadataFactory::callerSource()`` builds, on the
tool loop's own completions, and in a suspended run's persisted options, so a
run that resumes after an approval or an answer keeps it. It is never sent to
a provider. It is part of the answer-cache key, because it changes which
answers pass.

**The lists are a union.** ``TermListGuardrail`` reads the lists of the
configuration the call runs on, then those of each named configuration.
Every check runs over the union: a forbidden term of either side blocks, a
required term of either side is demanded, and a stream is held when either
side has a list. The block names the first list that matched, so two lists
banning the same term, or one list naming both configurations, give one
message. A list of a named configuration that cannot be read blocks, as a
list of the own configuration does.

**The rule-set judge takes the stricter mode.** The judge is attached to a
configuration (ADR-288): a mode (off, report, block), the
tags of its rule set and a judging configuration. For a call that names
further configurations, every side whose mode is not off is judged with its
own rule set by its own judging configuration. Every judge is held to the
trust-zone check against the provider that wrote the text, the call's own
configuration: a site on a local provider never has its text sent to the chat
configuration's external judge.
The violations of all sides are joined, and the strictest mode among them
decides what they do: block over report over off. A side whose judge gives no
usable answer counts as not checked under that strictest mode, and a named
configuration that does not exist or is inactive judges nothing. The same
violation from both sides, the same rule at the same passage, is named once.
Whether a stream is held or annotated follows the strictest mode too.

Consequences
============

- ● A caller that replaces its configuration keeps the bans of the one it
  replaced, without the administrator binding every list twice.
- ● The value survives a suspended run, like the site.
- ◑ A call that names further configurations reads more lists, one query per
  configuration.
- ◑ Each side's judge is a call of its own, so a call with judges on both
  sides pays for two.
- ◑ A site on a more trusted provider than the named configuration's judging
  provider cannot be judged by that judge. In block mode every judged write
  on that site then stops as not checked; in report mode it carries the "not
  checked" hint. The judge has to be moved to a provider at least as trusted
  as the site's.
- ◑ A named configuration that is switched off still adds its term lists and
  its judge; only one that no longer exists adds nothing.
- ✕ A caller that does not name its replaced configuration gets only the
  lists of the configuration it runs on, as before.

Net Score: +2

Alternatives considered
=======================

**Bind the lists to both configurations by hand.** Rejected by the decision:
it fails silently on every list nobody rebinds.

**Let the site's configuration decide alone.** Rejected: it drops bans, which
the privacy model never does.

**One judge for both sides, with the rules of both.** Rejected: each side's
rules were written for its own judging model, and joining them would apply
one side's rules with the other side's model. The text reaches both judges
either way. Whether a judge may receive it is decided by the trust-zone check
of ADR-288, measured against the provider that wrote the text: the
configuration the call runs on, never the named one.
