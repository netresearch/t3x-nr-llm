<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->

# A skill declares whether it needs a page

ADR229. A consumer such as the backend chat decides today from the process marker whether a conversation with a skill needs a page. The skill itself states it instead.

## Required behavior

1. The frontmatter key `page` takes `required`, `optional` or `none`, read case-insensitively after trimming. An absent key, a non-string value and any other string declare nothing.
2. The sync stores the declared value in `tx_nrllm_skill.page` on every create and update (`''` = not declared). A backend-authored skill sets it in FormEngine; a synced skill shows it read-only. The field is not `exclude`.
3. `Skill::getPageNeed()` (`@api`) returns the declared value as the `@api` enum `SkillPageNeed`, or null. nr_llm resolves no default; the documented default (process: required, other: optional) is the consumer's.
4. The field is not part of `SkillVersionDigest`, of the approval snapshot or of the version-digest upgrade wizard. A sync that changes only `page` keeps an enabled skill enabled, keeps its digest, and keeps its approvals matching.

## Not done

No default resolution in nr_llm, no `page` in `ProcessSkillCatalogue`, no validation error for an unknown value, no change to the upgrade wizard.

## Tests

- Unit `SkillFrontmatterTest`: every value class of item 1.
- Unit `SkillPageNeedTest`: the model exposes the stored value, null for none or an unknown one, null for an undeclared process skill.
- Unit `SkillVersionDigestTest`: no value of `page` changes the digest, the snapshot fields or the integrity check.
- Unit `SkillFieldExclusionTest`: `page` is not excluded, read-only for synced types, editable for the backend type.
- Functional `SkillVersionDigestSyncTest`: the sync stores and clears the value; a page-only change keeps the skill enabled, the digest equal and an existing approval matching.
- Mutation proof: adding `page` to the digest input turns the digest and sync tests red.
