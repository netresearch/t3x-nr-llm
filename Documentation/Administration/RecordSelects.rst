.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _administration-record-selects:

==============================
Choosing a record from a list
==============================

Several forms let you choose a model, a configuration, a task or a skill
from a list that shows only the names. The text under the list says what
the chosen record is for:

- a configuration: its description, and the model and provider it runs on
  (or that it picks a model by criteria);
- a task: its description, and the configuration it runs on;
- a model: its description, its provider and its model ID;
- a provider: its description and its adapter;
- a skill: its description and its identifier.

The text changes as soon as you choose another entry, also with the arrow
keys. In a list with two columns ("Selected Items" and "Available Items"),
it describes the entry you moved to last, so you can step through the
available entries and read each one before adding it. Screen readers read
the text when the list gets focus and again, after the entry's name, when
the choice changes.

.. figure:: /Images/backend-record-select-help.png
   :alt: The configuration list of a task, with the chosen configuration's
       description and model below it, and buttons to create a new
       configuration and to open the list of all configurations
   :class: with-border with-shadow
   :zoom: lightbox

   The configuration of a task: what the chosen configuration is for, and
   the buttons to create one and to show all of them.

Next to the list
================

:guilabel:`Create new` (plus icon)
   Creates a record of that kind and selects it. Shown only when you may
   create such a record on this page: the record type must be editable for
   you, and you need the right to edit content on the page.

:guilabel:`Show and manage all …` (list icon)
   Opens the backend module that lists these records. Shown only when you
   have access to that module. Unsaved changes in the form are offered for
   saving first.

Which lists have them:

.. list-table::
   :header-rows: 1

   *  - Form and field
      - Text below
      - Create new
      - Show and manage
   *  - Configuration: Model
      - yes
      - yes
      - Models
   *  - Configuration: Skills loaded always / on demand
      - yes
      - no (skills come from skill sources)
      - Skills
   *  - Task: Configuration
      - yes
      - yes
      - Configurations
   *  - Task: Attached Skills
      - yes
      - no
      - Skills
   *  - Model: Provider
      - yes
      - no (use the setup wizard)
      - Providers
   *  - Term list: Configurations
      - yes
      - no
      - Configurations

Other extensions use the same text and buttons for their lists of nr-llm
records, for example the landing page template's configuration and image
task in EXT:nr_landingpage.

.. _administration-record-selects-developer:

Using it in another extension
=============================

A ``select`` field whose ``foreign_table`` is one of the nr-llm record
tables gets the text and the buttons through three render types:

.. code-block:: php
   :caption: Configuration/TCA/Overrides/tx_myext_record.php

   'config' => [
       'type' => 'select',
       'renderType' => 'selectSingle',
       'foreign_table' => 'tx_nrllm_configuration',
       'fieldWizard' => [
           'nrllmChoiceHelp' => ['renderType' => 'nrllmChoiceHelp'],
       ],
       'fieldControl' => [
           'addRecord' => [
               'renderType' => 'nrllmAddRecord',
               'disabled' => false,
               'options' => ['setValue' => 'set'],
           ],
           'listModule' => [
               'renderType' => 'nrllmManageRecords',
               'disabled' => false,
               'options' => [
                   'module' => 'nrllm_configurations',
                   'title' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:choiceHelp.manage.configurations',
               ],
           ],
       ],
   ],

The text is written for ``tx_nrllm_provider``, ``tx_nrllm_model``,
``tx_nrllm_configuration``, ``tx_nrllm_task`` and ``tx_nrllm_skill``. Core's
``editPopup`` is not used: it works only with a list that holds several
entries. The decision is recorded in :ref:`adr-255`.
