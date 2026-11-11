.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

========================
AI-written record marker
========================

.. _administration-ai-written-records:

Records an AI wrote
===================

When an agent run or a fill run writes a page or a content element, the record
gets the field ``tx_nrllm_ai_written`` (:ref:`ADR-239 <adr-239>`). This built-in
marker works independently of the optional b13 AI Label integration below.
Without AI Label, a template decides what visitors see:

.. code-block:: html
   :caption: A notice in a content element template

   <f:if condition="{data.tx_nrllm_ai_written}">
       <p class="ai-notice">Written with the help of AI.</p>
   </f:if>

A page template using ``PAGEVIEW`` reads
``{page.pageRecord.tx_nrllm_ai_written}``.

A call that writes no AI text does not set the field: a move, a publish, a
change of a layout or another setting, a translation that failed and left the
source text. A copy of a marked record keeps the mark. The field sits on the
access tab, for every editor who may edit the record. An editor who has taken a
text over clears it; the next AI write sets it again. Rewriting the text does
not clear it on its own.

Only ``pages`` and ``tt_content`` carry the field. Records written before the
update carry no mark. For anything else, listen to
:php:`AfterAiRecordWrittenEvent`.

A translation that EXT:autotranslate makes through nr-llm is marked as well,
and labelled in AI Label as AI-modified (:ref:`administration-autotranslate`).

.. _administration-ai-written-ai-label:

Optional b13 AI Label integration
=================================

Install `b13/ai-label <https://github.com/b13/ai-label>`__ 1.6 or later for
origin labels, editorial review and configurable frontend notices:

.. code-block:: bash
   :caption: Install AI Label in the TYPO3 project

   composer require b13/ai-label:^1.6

Apply the database changes and rebuild TYPO3's caches after installation.
The integration activates automatically when the extension is loaded. AI Label
is optional; removing it leaves nr-llm's built-in marker available.

The integration labels successful agent and field-fill writes to ``pages`` and
``tt_content``, provided AI Label enables the table:

* A complete write by ``create_content_element_draft`` is AI-generated when
  it creates a ``text`` element with explicitly supplied, nonempty body text.
* Page creation, translations, field fills and other text writes are
  AI-modified: they establish an AI contribution without claiming that all
  content was generated. An existing AI-generated origin is preserved.
* Each AI text write clears AI Label's human review. Approving a tool call
  does not mark the resulting text as reviewed.
* Moves, publishing, unchanged copies and deletions do not add a label. File
  metadata writes are excluded: AI-written alt text says nothing about an
  image's origin.

Writes use AI Label's public API with the acting backend user's permissions
and workspace. Hidden and scheduled drafts receive labels too. A draft's label
stays in that draft. A failed label write is
logged; the content write remains successful and the built-in marker remains.
Check TYPO3's error log if a record has the built-in marker but lacks the
expected AI Label origin.

The two markers are independent. Clearing ``tx_nrllm_ai_written`` does not
clear AI Label's origin or review state, and changing AI Label does not clear
the boolean. Existing records are not backfilled when AI Label is installed.

Frontend notices require AI Label's TypoScript. For site sets, add its set
dependency and explicitly import its setup; the dependency alone does not
include it. Classic projects include its static template. Follow its
`frontend setup <https://github.com/b13/ai-label#frontend-integration>`__ and choose
one presentation for your site. Remove the template notice above if AI Label
already renders the notice there, to avoid duplicate labels.
