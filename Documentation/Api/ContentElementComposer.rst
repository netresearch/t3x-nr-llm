.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _api-content-element-composer:

===========================================
Content elements with their child records
===========================================

:php:`ContentElementComposer` gives another extension the content-type rule
of ``create_content_element_draft`` (:ref:`ADR-324 <adr-324>`): which
content types a draft can fill, what a model may put into one, which of them a
page and a user allow, and the DataHandler datamap of one element with its
child records. nr_landingpage's Page Composer builds its sections through it.

.. code-block:: php
   :caption: Describing a type and building one element of it

   use Netresearch\NrLlm\Service\Tool\ContentType\ContentElementComposer;

   final readonly class MySectionWriter
   {
       public function __construct(
           private ContentElementComposer $composer,
       ) {}

       public function accordion(int $parentPage, BackendUserAuthentication $user): array
       {
           // What a model may fill, for the prompt; null when not offered.
           $shape = $this->composer->describe('accordion', $GLOBALS['LANG'] ?? null, $user);

           $map = $this->composer->dataMap(
               'accordion',
               'NEW_content_0',
               [],
               ['tx_bootstrappackage_accordion_item' => [
                   ['header' => 'Question', 'bodytext' => '<p>Answer.</p>'],
               ]],
               'NEW_page',
               $parentPage,
               0,
               $user,
           );
           if ($map->isRefused()) {
               throw new \RuntimeException(implode(' ', $map->refusals));
           }

           // Merge CType, header, pid and position into
           // $map->dataMap['tt_content']['NEW_content_0'] and write it,
           // together with the page, in one DataHandler call.
           return $map->dataMap;
       }
   }

:php:`offeredTypes()`
   The content types a draft can fill on this installation, in the order the
   TCA declares them.

:php:`describe()`
   One offered type as an array a model reads: ``type``, ``label``,
   ``description`` (the TCA item's description, a usage hint), ``fields``
   (column to ``type``, ``label``, ``required`` and, where they apply,
   ``max``, ``items``, ``richText``) and ``children`` (inline column to
   ``table``, ``maxItems`` and the ``fields`` of its records). With a
   user, the columns that user may not set are left out. ``null`` for a type
   the rule does not offer. A later version may add keys; none is removed.

:php:`typesOfferedOnPage()`
   The candidates a user may create on a page: those the page's
   ``TCEFORM.tt_content.CType.keepItems`` and ``removeItems`` keep (not
   read for page ``0``), that the user's ``authMode`` on ``CType``
   allows, and whose child records the user may write. A candidate the rule
   does not describe is kept when the page and the user allow it.

:php:`dataMap()`
   :php:`ContentElementDataMap`: the columns to merge into the caller's
   element row (further ``fields``, FlexForm defaults, the inline columns
   naming the child placeholders) and the child rows per table, or the
   refusals (:php:`isRefused()`, :php:`$refusals`). Every value is checked
   against the TCA and the page TSconfig of ``$pageUid``, and every grant
   the DataHandler would ask - ``tables_modify``, exclude fields,
   ``authMode`` - before anything is written. ``$pid`` is what the rows
   get: a page uid or the placeholder of a page in the same datamap.
   Nothing is written here; the caller writes the datamap.
