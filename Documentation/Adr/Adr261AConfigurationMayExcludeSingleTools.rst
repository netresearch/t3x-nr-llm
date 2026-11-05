.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-261:

=================================================
ADR-261: A configuration may exclude single tools
=================================================

:Status: Accepted
:Date: 2026-11-05
:Authors: Netresearch DTT GmbH

Context
=======

An LLM configuration narrows the tools of its runs through
:guilabel:`Allowed tool groups` (:ref:`ADR-043 <adr-043>`). The group is the
smallest unit it can name. To keep one tool from a configuration, an
administrator has to drop its whole group: a configuration that may use every
writing tool except ``delete_record`` loses all twenty-two tools of ``editing``.
The global switch in the Tools module is no answer either, because it takes the
tool away from every configuration.

The tool gate (:ref:`ADR-094 <adr-094>`) is the one place that decides whether
a tool is offered and whether a call may run. A run resolves its skill
allow-list once when it starts, stores it, and on resume intersects it with the
list resolved then (:ref:`ADR-038 <adr-038>` item 5), so a change while the run
waits can take a tool away but never add one. The group gate is read live on
every decision instead.

Decision
========

1. **A field of single tools.** ``tx_nrllm_configuration`` gains
   ``excluded_tools``, a comma list of tool names, edited as
   :guilabel:`Excluded tools` with a choice of the registered tools grouped by
   tool group. A stored name no tool carries any more stays listed under
   :guilabel:`No longer registered`, so it can be removed. Empty excludes
   nothing; every existing row keeps its tools.

2. **It only narrows.** :php:`ToolCallPolicy` refuses an excluded tool with the
   new reason ``configurationExcluded``
   (:php:`ToolDenialReason::CONFIGURATION_EXCLUDED`). The check runs after the
   global state, the admin requirement, the skill allow-list and the group
   gate, so the reason is reported only when those let the tool through, and
   before the trust zone, so observe mode cannot offer an excluded tool. The
   field grants nothing: it is a deny list, and a name in it never reaches a
   path that adds a tool.

3. **Offer and execution.** The same decision filters the tools offered to the
   model and is asked again when a call is executed, and by the approval and
   input submit gates of :php:`ResumeCoordinator`. A call the model sends to an
   excluded tool is refused like any call to a tool it was not offered.

4. **Stored with the run.** The exclusions at run start ride the run's
   :php:`SkillToolAllowList` as ``excludedToolNames``, so the queue, a
   suspended run and an approval carry them without a field of their own. The
   gate refuses the union of those and the configuration's live exclusions. An
   exclusion added while a run waits applies at once; one removed meanwhile
   still holds for that run. An intersection keeps the exclusions of both
   sides. The stored form writes the key only when there are exclusions, so
   the stored form of every other run is unchanged, and a run stored before
   reads back without exclusions and is held to the live list. A stored value
   that is not a list cannot say what it excluded and admits no tool, the skill
   load tool included.

5. **A tool registered later is not excluded.** The field names tools, not a
   pattern or a group. A tool an extension or an MCP server adds later is
   offered until someone selects it.

6. **No exemption for the skill load tool.** :ref:`ADR-214 <adr-214>` keeps
   ``load_skill`` outside the skill allow-list and the group gate, because a
   skill declaring ``allowed-tools: []`` should not switch loading off. An
   exclusion names that very tool, so it applies to ``load_skill`` too.

7. **Visible where tools are managed.** The unavailable-tools list of
   :ref:`ADR-201 <adr-201>` reports an excluded tool with its own reason. The
   Tools module names, under each tool, the active configurations that exclude
   it, and the governance tab of the overview module lists the excluded tools
   per active configuration. Both read the field through
   :php:`ToolExclusionReadout`, which decides nothing; the simulation on the
   same tab asks the gate itself.

Consequences
============

* An administrator can withhold one tool from one configuration without
  touching the others or the tool's group.
* A run resumed after its configuration dropped an exclusion stays without the
  tool. Starting a new run gives it back.
* The dashboard widget of tool denials shows a bar of its own for the new
  reason.
* Configuration presets do not carry the field. A preset import leaves it as it
  is.

Alternatives considered
=======================

* **An allow-list of single tools.** A configuration that names its tools would
  have to be edited whenever a tool is added, and a tool registered later would
  be refused by default. The ticket asks for the opposite: narrow by name,
  leave new tools alone.
* **Reading the exclusions live only, like the group gate.** Simpler, but a run
  waiting for an approval would get a tool back when its exclusion is removed,
  which the run never had at its start.
* **A field of its own on every stored run.** It would repeat the storage the
  skill allow-list already has, in the codec, the suspended state and the
  approval path, for the same "may only narrow on resume" rule.
