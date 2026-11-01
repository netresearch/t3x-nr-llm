.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-235:

================================================
ADR-235: A vision editor action sends the image
================================================

:Status: Accepted
:Date: 2026-11-01
:Amends: :ref:`ADR-158 <adr-158>` (the catalogue reads a record for a vision action), :ref:`ADR-152 <adr-152>` (the declaration names the fields an action fills from an image)
:Authors: Netresearch DTT GmbH

.. _adr-235-context:

Context
=======

``set_file_alternative_text`` and ``update_fal_asset_meta`` are editor actions
on ``sys_file``. The run they start says "table sys_file, uid 7", and nothing
else reaches the model: it has no tool that shows it the image and no message
that carries one. The action can only guess from nothing, and an alternative text
written that way is worse than none.

:php:`VisionService` analyses an image, but it is a separate call without tools.
The run has to end in the tool call, because a declared write suspends for
approval there (:ref:`ADR-134 <adr-134>`), so the image has to be in the run.

.. _adr-235-decision:

Decision
========

**An action declares the fields it fills from an image.** :php:`EditorAction`
gains ``visionFields``. A non-empty list makes the action a vision action; the
two tools name their fields.

**The image is a message of its own.** The run holds the prompt as an ordinary
string message and a second user message whose content is a part list with the
image as a data URI. The providers already convert such a part list: OpenAI and
OpenRouter pass it on, Claude and Gemini translate it. A single part-list message
would be shorter and would be wrong: :php:`InputGuardrailScreener` reads string
content only, so the editor's note would pass it unscreened.

**The image is scaled first, and the result is measured.** A vision provider
needs no 6000-pixel photograph, and a large one would cost more on every send.
:php:`FalImageDownscaler` uses TYPO3's file processing, which caches the result in
the storage's processed folder. Processing that fails hands back the original
without an error, so the bytes are measured: an image whose longest edge is over
``vision.maxImageEdge``, and a format no provider takes, are not sent. The
original is never the fallback.

**Who may send which file.** The file is resolved in the catalogue, before the
run, which :ref:`ADR-158 <adr-158>` otherwise forbids. It has to be: the image is
in the run from the first send. The read goes through :php:`FalStorageGate`, the
gate the tool itself uses, as the acting user. A file that does not exist, is not
permitted, lies in an excluded folder or cannot be scaled gives one answer, no
run, and the editor sees the message for an action that is not available. So the
reason a catalogue had for not reading still holds: no uid is confirmed.

**An action that cannot send the image does not run.** Falling back to a run
without it would be the defect this record removes. On a model that declares
capabilities without ``vision`` the action is not offered; a model that declares
none passes, as in model selection (:ref:`ADR-138 <adr-138>`).

**The operator configures per field**, in the extension configuration:
``vision.<field>.enabled``, ``.maxLength`` and ``.prompt`` for ``alternative``,
``title`` and ``description``, plus ``vision.maxImageEdge`` and
``vision.excludedFolders``. There is no page context for a file, so TSconfig is
no place for them.

- ``enabled`` governs the editor action. An action with no field on is not
  offered, and a field that is off is not named in the prompt. It does not stop
  the assistant from using the tool: that is what the tool's own availability is
  for.
- ``maxLength`` is a rule about the field and applies to every writer of it,
  because the tool enforces it. The shipped value is 0, no limit of its own, so a
  fresh installation behaves as before and the shipped prompts ask for a length
  in words. A limit is named to the model as nine tenths of itself: models count
  characters badly, and an over-long value is refused on the approval card.
- ``.prompt`` replaces the shipped text for that field. It is the operator's text
  and is not fenced like the editor's note.

The prompt asks for the language of the acting backend user. A file has no page
and so no site language to take it from.

.. _adr-235-consequences:

Consequences
============

- An image leaves the installation, to the provider of the default configuration.
  That was never the case for these actions, and an operator who must keep a
  folder out names it in ``vision.excludedFolders``.
- Input guardrails do not read images. Text in an image reaches the model
  unscreened. The single-tool allow-list, the acting user's rights and the
  approval card are the limits on what follows from it.
- The image is part of the transcript. It is in ``suspended_state``, sealed, until
  the run settles; in the event stream only at ``privacy.level = full``; and it is
  sent again with the send after approval, which costs its tokens a second time.
- :php:`TranscriptEstimator` counts string content, so the cost estimate on the
  batch page of :ref:`ADR-162 <adr-162>` does not count the image. It is an
  underestimate of the kind that record lists.
- An editor whose image cannot be sent reads that the action is not available,
  and not why. The price of confirming no uid.
- ``set_file_reference_alternative_text`` has no editor action and is unchanged.

.. _adr-235-alternatives:

Alternatives considered
=======================

**A tool that returns the image to the model.** Tool results are strings, on
every provider path here, and a read tool in a one-tool run would have to be
offered and paid for as a round trip of its own.

**Generate with :php:`VisionService`, then start the run with the text.** Two
model calls, two configurations to keep apart, and a generated text in a prompt
that tells a model to repeat it.

.. _adr-235-revisit:

Revisit when
============

- Providers return image input cost separately: the estimate can count it then.
- A site language can be derived for a file, for example from the page that uses
  it.
