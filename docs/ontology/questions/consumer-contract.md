---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
type: Competency Question
title: "What a consuming extension relies on"
description: "Which concepts form the contract of nr-llm towards consuming extensions?"
x-nr-query: --needs concepts/public-api
status: draft
generated: {by: claude-code/2.1.332, at: 2026-11-27T21:06:57Z}
---

# Question

An extension calls nr-llm. Which part of nr-llm may it rely on, and which tests protect that part?

# How to answer

Read the public API concept and its tests. A consuming extension relies on the `@api` surface only; everything marked `@internal` can change without notice. Run:

```bash
python3 Build/Scripts/check-ontology.py docs/ontology --needs concepts/public-api
```

# Answer at the time of writing

```text
concepts/public-api
  test Tests/Unit/Api/ApiSurfaceSnapshotTest.php
  test Tests/Unit/Api/DeprecationInventoryTest.php
Scope: only the relations this bundle models; coverage.md names what it leaves out.
```

# Limits

The consuming extensions are outside this repository. This bundle does not list them.
