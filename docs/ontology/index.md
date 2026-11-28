---
# SPDX-License-Identifier: GPL-2.0-or-later
# SPDX-FileCopyrightText: Netresearch DTT GmbH
okf_version: "0.3"
title: nr_llm ontology
description: The concepts of nr_llm and their typed relations, each checked against the code, for questions such as what a change to a provider affects.
license: GPL-2.0-or-later
---

# nr_llm ontology

This bundle defines the concepts of nr_llm (providers, models, configurations, agent runs, skills, tools, budgets) and states how they relate. Every asserted relation names the file in this repository that shows it, and a relation without such a file has the status `hypothesis`, so the bundle can answer questions such as "a provider changes: which concepts can that affect, and which tests run again?" without reading the whole code base.

This bundle follows the Open Knowledge Format 0.3 (https://okf.md): one Markdown file per concept, with a YAML frontmatter block that names its `type`.

# Use it

Run the check from the repository root. `composer ci:test:ontology` runs the first command, and `composer ci:test:repo` includes it.

```bash
python3 Build/Scripts/check-ontology.py docs/ontology                                  # check the bundle against the code
python3 Build/Scripts/check-ontology.py docs/ontology --impact concepts/provider     # what a change to it can affect
python3 Build/Scripts/check-ontology.py docs/ontology --needs concepts/agent-run       # what it depends on
```

The check fails when an asserted relation loses its evidence in the code. Fix the relation in the same change as the code.

# Contents

* [Concepts](concepts/index.md) - The concepts of nr_llm, one file each
* [Relations](relations/index.md) - The relation vocabulary: what each relation means and in which direction a change propagates
* [Competency questions](questions/index.md) - The questions this bundle is built to answer, with the answer at the time of writing
* [Coverage](coverage.md) - What the nr_llm ontology models, what it leaves out, and how to read its statuses.

# Format of a concept

Every concept file has the sections `# Definition`, an optional `# Not to be confused with`, `# Relations` and optional `# Notes`. The relations table has five columns:

| Column | Content |
|---|---|
| Relation | A relation of the vocabulary in `relations/` |
| Target | A link to a concept, a path in this repository in backticks, or an external URL |
| Evidence file | The file, relative to the repository root, that shows the relation |
| Evidence text | A literal of that file; the check requires it exactly once and ignores differences in whitespace |
| Status | `asserted`: read in the code and checked by the script. `hypothesis`: not yet backed by evidence |

The relation vocabulary is the same in every Netresearch bundle. Netresearch keeps the canonical copy of the vocabulary and of this script, and the relations between repositories, in an internal repository.
