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
gets the field ``tx_nrllm_ai_written`` (:ref:`ADR-239 <adr-239>`). The extension
shows nothing to visitors. A template decides:

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
