.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-245:

==================================================================
ADR-245: A task is marked for the places that offer it
==================================================================

:Status: Accepted
:Date: 2026-11-02
:Authors: Netresearch DTT GmbH

Context
=======

Three places offer task records to a person, and each chose them differently:

- The editors' module *AI tasks* (:php:`AiTaskController::listAction()`)
  listed every active task, grouped under the raw category key, so an editor
  saw log analysis and developer tasks beside the ones prepared for them.
- The landing page template field *Bildquelle* of nr-landingpage selected
  every task that is not hidden. On an installation with twenty text tasks the
  editor chose between "Translate to German" and "Summarize" to switch on image
  generation.
- The cowriter dialog asks :php:`TaskRepository::findByCategory()` for the
  fixed category ``content``.

What the landing page field actually does was read from the code before the
decision. The task does not pick the image model:
:php:`ImageProviderService::generateImageForSection()` passes the generators a
size and a quality only. The OpenAI generator, :php:`DallEImageService`,
resolves the model through the model registry (an active record with the
capability ``image``, otherwise ``gpt-image-2``); the fal.ai generator falls
back to ``flux-schnell``. Nothing reads the task's ``configuration_uid``. The
configuration an :php:`ImageGenerationOptions` carries attributes a call for
budgets and analytics and selects no model either. A selected task switches
generation on, and its ``prompt_template`` is the image prompt used for a
section the content model gave no prompt of its own
(:php:`ImageProviderService::buildImagePrompt()`). Filtering the field by
"configurations that can generate images" would therefore filter by something
the field does not use.

The category cannot carry "where is this offered". It holds one value, is a
fixed list of five, and classifies what a task does. A task that suits the AI
tasks module and the cowriter at once has no category saying both, and a new
consumer has no category at all.

Decision
========

**A task carries a list of placements, and a place that offers tasks names
its placement.** The column ``tx_nrllm_task.placements`` holds the keys of the
places the integrator marked the task for. nr-llm declares one,
``ai_tasks`` (:php:`Task::PLACEMENT_AI_TASKS`). Another extension appends an
item for each of its places in its own
``Configuration/TCA/Overrides/tx_nrllm_task.php``, so the form lists every
place that exists on the installation and nr-llm names no consumer.

**Marked tasks come first, in a group of their own.** A TCA select of tasks
names its placement in ``itemsProcConfig.placement`` and calls
:php:`TaskPlacementItems::groupByPlacement()`. The marked tasks form the group
``nrllm_placement``; the field's static items, such as a "none" entry, keep
their position ahead of it. What happens to the rest is the field's choice,
``itemsProcConfig.unmarked``:

- ``after`` (default): the other tasks follow in the group ``nrllm_unmarked``.
- ``hide``: they are left out — except the task the record already holds,
  which stays in ``nrllm_unmarked`` so saving the form does not change it, and
  except while no task carries the mark at all, when every task is shown so
  that an installation that has not marked anything yet can still choose.

The field declares both groups in its ``itemGroups``, with labels that say
what its place is.

**The AI tasks module shows its marked tasks first.** They form the first
group, *Prepared for this module*; a task marked for no place follows under
its category's translated label. A task marked for other places only is left
out: it was prepared for those, and an image prompt run here would answer with
text. Only places an installed extension declares count, so a task marked for
the place of a removed extension is listed again rather than hidden by a key
its form can no longer show. An installation that marks nothing sees the list it saw before, grouped
by readable labels.

**The landing page field hides the unmarked tasks.** Its options are image
prompts, and a text task chosen there produces an image prompt like
"Translate to German: <heading>". The field is labelled and described as the
choice of an image prompt template that switches generation on, and says that
the image model comes from nr-llm's image model records. That change and its
placement key ``landingpage_image`` belong to nr-landingpage.

**The cowriter keeps its category for now.** Its list is already narrowed,
by ``content``, and the tasks the editorial starter pack creates for it carry
that category. Moving it to a mark means a cowriter placement key, an upgrade
wizard that marks every ``content`` task so that today's list survives, and a
mark on the tasks the starter pack creates, which would make nr-llm name a
consumer's key. That is the change to make when the cowriter needs a set that
differs from the category; the mechanism is in place for it.

Alternatives
============

**More category values.** One value per task, a fixed list nr-llm owns, and
a classification would turn into a routing table. Rejected.

**A tag table with an MM relation, or ``sys_category``.** Either one lets an
editor invent free-form tags. A placement is not free-form: it is useful only
when a place reads it, and the select of declared places prevents a typo from
marking a task for nowhere. Rejected for that reason; a comma list of declared
keys needs no relation table and no reference index.

**Making the landing page field a choice of an image configuration.** The
generators take no configuration that selects a model, so the field would be
a second switch with a misleading name until the image path is reworked.
Rejected for now.

Consequences
============

- ●● An editor sees the tasks prepared for the place first, and the landing
  page field offers image prompts only once one is marked.
- ● A consumer adds a place with one TCA item and one ``itemsProcConfig``,
  without code in nr-llm.
- ◐ The AI tasks module labels its groups instead of printing category keys.
- ◑ A new column needs the database schema update.
- ◑ An integrator has to mark tasks; until then every place behaves as before.
- ◑ The cowriter keeps a second, category-based selection rule.

Net Score: +4.5
