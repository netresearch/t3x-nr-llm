<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# A table task may send its rows as TOON

> [Inventory](../README.md).

A table task hands up to 50 rows, every column, to the model as pretty-printed
JSON. Over the first 50 rows of five demo tables, TOON (Token-Oriented Object
Notation, specification 4.4) needed 66.9 % fewer `o200k_base` tokens for the
same rows, 45.0 to 71.0 % per table. Decision record: ADR-286.

## What it must do

- Read a `format` option from a table task's `input_source` JSON; `"toon"`
  selects TOON, a missing or any other value keeps JSON.
- With TOON selected, encode the rows per specification 4.4 when they are a
  non-empty list of objects with the same set of keys and only primitive
  values, using a maintained library rather than an own encoder.
- Put one line in front that says how to read the header, and the rows in a
  `toon` code fence that no value can close.
- Leave the JSON text byte for byte as before whenever TOON is not used.

## What it explicitly does not do

- No TOON for rows that are not one flat table (no rows, differing keys,
  nested values) or that the library refuses (strings that are not UTF-8);
  they stay JSON.
- No change to the record picker of the run form, to syslog or
  deprecation-log input, to tool results or to agent runs.
- No extension-wide switch and no change of the default.
- No measurement of answer quality on TOON input.

## Which suite proves each requirement

| Requirement | Suite and test |
|---|---|
| Option read from `input_source` | unit [TaskInputResolverTest](../../Tests/Unit/Service/Task/TaskInputResolverTest.php) `tableFormatOptionDecidesBetweenToonAndJson`; unit [TableInputEncoderTest](../../Tests/Unit/Service/Task/TableInputEncoderTest.php) `theFormatOptionSelectsToonOnlyWhenItSaysSo` |
| Encoding per specification 4.4, fallback for other rows | unit [TableInputEncoderTest](../../Tests/Unit/Service/Task/TableInputEncoderTest.php) on the specification's tabular and primitive fixtures, `rowsThatAreNotOneTableStayJson`, `aStringThatIsNotUtf8LeavesTheRowsToJson` |
| Note line and fence | unit [TableInputEncoderTest](../../Tests/Unit/Service/Task/TableInputEncoderTest.php) `theFenceIsLongerThanAnyRunOfBackticksInTheRows` |
| Prompt of a table task, SQLite and MariaDB | functional [TableInputToonTest](../../Tests/Functional/Service/Task/TableInputToonTest.php) |
