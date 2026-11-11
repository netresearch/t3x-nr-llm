.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-286:

===============================================
ADR-286: A table task may send its rows as TOON
===============================================

:Status: Accepted
:Date: 2026-11-11
:Authors: Netresearch DTT GmbH

Context
=======

A task whose input type is ``table`` reads up to ``limit`` rows (default 50,
every column) through :php:`RecordTableReader::fetchAll()` and hands them to
the model as pretty-printed JSON, which repeats every column name in every
row. :php:`TaskInputResolver` builds that text; the run form shows it, the cost
estimate counts it and :php:`TaskExecutionService` puts it into the prompt.

TOON (Token-Oriented Object Notation, `specification
<https://github.com/toon-format/spec>`__) carries the JSON data model and
writes an array of objects that share their keys as one header with the
field names, followed by one line of values per row.

Where JSON reaches a model at all was measured on 2026-11-04 before anything
was built. The built-in tools answer in text lines, not JSON; syslog and
deprecation-log task input is text too. The one path that sends JSON tables is
the table task, and the demo installation had no such task then.

The saving on that path was measured again on 2026-11-11 with the encoder this
record chooses, over the first 50 rows of five tables of the typo3-demo
database (``data/db.sql.gz`` at ``ea0a6f0c``). The rows were read through
Doctrine DBAL's mysqli driver with a bound parameter, as the reader's query
has one, so the values carry the PHP types a task sees (integers as integers).
Each TOON text decodes back to the same rows. Tokens were counted with
``o200k_base`` (tiktoken 0.12.0); **assumption:** that tokenizer stands in
for the demo's OpenAI model, other providers tokenise differently.

.. list-table:: Tokens per table input, o200k_base
   :header-rows: 1

   * - Table
     - Rows
     - JSON today
     - Compact JSON
     - TOON
     - vs. today
     - vs. compact
   * - pages
     - 50
     - 40,007
     - 26,948
     - 11,610
     - −71.0 %
     - −56.9 %
   * - sys_file
     - 50
     - 11,780
     - 8,884
     - 6,482
     - −45.0 %
     - −27.0 %
   * - sys_file_metadata
     - 50
     - 18,279
     - 11,705
     - 5,295
     - −71.0 %
     - −54.8 %
   * - sys_redirect
     - 4
     - 895
     - 595
     - 411
     - −54.1 %
     - −30.9 %
   * - tt_content
     - 50
     - 54,729
     - 38,407
     - 17,799
     - −67.5 %
     - −53.7 %
   * - All five
     - 
     - 125,690
     - 86,539
     - 41,597
     - −66.9 %
     - −51.9 %

The TOON column includes the explanatory line and the code fence described
below, 49 tokens per input. Only token counts were measured; whether a model
answers a given task as well from TOON as from JSON was not.

Decision
========

**A library, not an encoder of our own.** The encoding is
``helgesverre/toon`` (MIT, PHP ^8.1, no dependencies), required as ``^3.3``
and called with ``SpecVersion::V4_4``, the current specification. Run with
that version, release 3.3.0 passes all 160 encode fixtures of specification
4.4.0; its own ``VersionedFixturesTest`` runs them per version. Without the
version argument it keeps its 3.3 profile, which fails 38 of them, among them
a ``#``-leading cell that a 4.x decoder reads as a comment line, so the
argument is pinned by a test. nr-llm requires Composer
(:ref:`installation-manual`), so a library dependency reaches every supported
installation. Other PHP packages were evaluated on 2026-11-11 and not taken:
``rrp/t3-toon`` implements specification 3.3 and depends on TYPO3 itself;
``iamgerwin/toon-php`` names no specification version and was last released
in January 2026; ``mateuszkardas/toon`` implements specification 1.3 in a
0.1.0 from 2025; the Laravel packages depend on Laravel.

**Opt-in per task.** A table task sends TOON when its ``input_source`` JSON
holds ``"format": "toon"``, the place where ``table`` and ``limit`` already
live. A missing or unknown value is JSON, and the JSON text is byte for byte
what it was before. TOON is not the default because a task's prompt template
may say "the JSON below", and because how a model reads TOON for a given task
was not measured.

**Only rows that are one table.** :php:`TableInputEncoder` uses TOON only
when the rows are a non-empty list of objects that share the same set of keys
and hold only primitive values, the specification's tabular form with flat
columns. Anything else stays JSON, with the same result as before: no rows,
rows whose keys differ, a nested value, and rows the library refuses (a string
that is not valid UTF-8, for which JSON produces ``[]`` as it always did).
Rows of ``SELECT *`` share their columns, so a table task's rows always
qualify unless a column holds bytes that are not UTF-8.

**The model is told the format.** One line in front of the rows says how to
read the header, and the TOON text sits in a ``toon`` code fence, as the TOON
README recommends for model input. The fence is longer than any run of
backticks in the rows, so no value can close it.

**Where it does not apply.** The record picker of the run form loads chosen
records into the editable input field as JSON; it has no task to read an
option from and stays JSON. Tool results, agent runs and the other input types
are text and are not touched.

Consequences
============

- ●● A table task that opts in sends 45 to 71 % fewer tokens for the same rows
  in the measurement above, and its cost estimate counts the shorter input.
- ● Nothing changes for an installation that does not set the option.
- ◐ The rows stay readable in the run form, where the administrator sees the
  text that is sent.
- ◑ A new runtime dependency. Its encoder tracks the specification; the
  specification's fixtures for tables and primitives are copied into nr-llm's
  tests, so a library update that changes the output fails there.
- ◑ Answer quality on TOON input is not measured; a task owner compares both
  formats on the task before switching.

Net Score: +4

Alternatives considered
=======================

**An encoder of our own.** Rejected: a maintained library that passes the
specification's fixtures exists, and the quoting rules (numeric-looking
strings, leading ``-`` and ``#``, delimiters, escapes) are where an own
encoder would drift.

**TOON as the default for table tasks.** Rejected for now: it would change the
input of existing tasks whose templates name JSON, without a measurement of
answer quality.

**An extension-wide switch.** Rejected: the choice depends on the task's
prompt, not on the installation.
