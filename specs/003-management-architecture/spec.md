# Non-admin record management

**Feature directory**: `specs/003-management-architecture` | **Created**: 2026-08-17 | **Updated**: 2026-08-19 | **Status**: Draft | **Source**: [#768](https://github.com/netresearch/t3x-nr-llm/issues/768), [#691](https://github.com/netresearch/t3x-nr-llm/issues/691) | **Record**: ADR-169 (`Accepted` 2026-08-18)

## Overview

An editor holding the right TYPO3 permissions should be able to maintain the extension's own content — tasks, prompt snippets, configurations — without an administrator. ADR-169 was accepted section by section on 2026-08-18 and settles what the first draft of this document had to leave open.

**This specification does not repeat ADR-169's analysis.** It states what the accepted decisions require, and defers to the record for why.

## What the accepted record settles

| ADR-169 | Accepted answer |
|---|---|
| §1 Where records live | **Option P** — on a page inside a web mount. No core-permission change; `security.ignoreRootLevelRestriction` stays closed |
| §2 Which records | `tx_nrllm_task`, `tx_nrllm_promptsnippet`, `tx_nrllm_configuration` — the third with its governance and spend fields excluded |
| §3 Bypassing write paths | The two wizards write through `persistAll()`, so no record permission, exclude field or TCA validation runs on them |
| §4 The surface | **Option C** — FormEngine plus `exclude => true` on the fields that must not travel with `tables_modify` |
| §5 The grant name | Neither. No nr_llm grant; `tables_modify` and `non_exclude_fields` govern. [#691](https://github.com/netresearch/t3x-nr-llm/issues/691) is answered, and the ADR-130 / ADR-131 reservation retired |
| §7 Enforcement | No grant case. Preset import and use-case-pack install move onto the DataHandler; the tool kill switch stays admin-only, since `tx_nrllm_tool_state` has no TCA to express a boundary in |

**§6 is the one section not accepted.** Module placement is [#812](https://github.com/netresearch/t3x-nr-llm/issues/812) with ADR-119 as its addressee, because reopening it creates a top-level backend section shared with three other extensions. This feature therefore says nothing about where the surface lives in the module tree — and does not need to, since none of the requirements below depend on it.

## Requirements

- **FR-001**: A non-administrator holding `tables_modify` on `tx_nrllm_task`, `tx_nrllm_promptsnippet` or `tx_nrllm_configuration`, with content-edit permission on the page the record sits on, MUST be able to create, edit and delete that record without an administrator acting.
- **FR-002**: No other `tx_nrllm_*` table MAY become editable by a non-administrator. The six exclusions rest on distinct reasons — credentials, the host the extension talks to, spend governance — and widening the set is a separate security decision, not a scope adjustment.
- **FR-003**: On `tx_nrllm_configuration`, the governance and spend fields MUST carry `exclude => true`, so that `tables_modify` alone does not convey them. They travel only with an explicit `non_exclude_fields` grant.
- **FR-004**: The exclude boundary MUST hold on every write path reaching these three tables. `SetupWizardController` and `TaskWizardController` bypass the DataHandler today, so each either moves onto it or its surface stays administrator-only. **A boundary honoured on one path and ignored on the other is worse than none, because the TCA then reads as enforcement it does not provide.**
- **FR-005**: `security.ignoreRootLevelRestriction` MUST stay closed on all nine tables. Option P was accepted precisely so no installation-wide capability is opened; a change here reverses the decision rather than extending it.
- **FR-006**: An installation that has not granted `tables_modify` MUST see no behaviour change, and existing pid-0 records MUST stay reachable by the runtime exactly as before.
- **FR-007**: Moving existing records off pid 0 onto a page MUST be a deliberate operator action, never an automatic side effect of an upgrade. ADR-169 §1 names this as one of Option P's two accepted costs.
- **FR-008**: Documentation MUST state what page storage does **not** do: nothing filters by pid at runtime, so a record created on any page is live for the whole installation. "Storage folder" is a permission boundary and a convention, not a scope.

## User scenarios

### Scenario 1 — an editor maintains a prompt snippet (Priority: P1)

- **Given** a non-admin with `tables_modify` on `tx_nrllm_promptsnippet` and edit permission on the page holding it, **When** they change the snippet text, **Then** the change saves and is attributed to them.
- **Given** the same user and a `tx_nrllm_provider` record, **When** they attempt to edit it, **Then** the DataHandler refuses.

**Independent test**: functional, one permitted and one forbidden table for the same user.

### Scenario 2 — the excluded fields do not travel (Priority: P1)

- **Given** a non-admin with `tables_modify` on `tx_nrllm_configuration` and no `non_exclude_fields` entry for its governance and spend fields, **When** they open the record, **Then** those fields are not editable, and a hand-crafted request that sets them does not persist them.

**Independent test**: functional, asserting the persisted row, not the rendered form. The form hiding a field is not the boundary.

### Scenario 3 — the bypassing path (Priority: P1)

- **Given** a write path that does not run the DataHandler, **When** a non-admin reaches it, **Then** either it enforces the same exclude boundary or they cannot reach it at all.

**Independent test**: functional, one per bypassing path, asserting a non-admin cannot write through it what the checked path would refuse. This is the assertion that makes FR-004 real rather than stated.

### Scenario 4 — an installation that did not ask for this (Priority: P2)

- **Given** an installation with no `tables_modify` grant on the three tables, **When** it is upgraded, **Then** no non-admin gains an ability, no record moves, and pid-0 records stay reachable.

## Success criteria

- **SC-001**: A named editor role completes snippet and task maintenance with no administrator involved — measured by doing it, not by reading the permission matrix.
- **SC-002**: For every write path reaching the three tables, a test asserts the boundary. The criterion is that the count of paths **without** such a test is zero.
- **SC-003**: No credential-bearing or egress-governing table is editable by a non-administrator, asserted against persisted rows.
- **SC-004**: `grep` for `ignoreRootLevelRestriction` across the repository returns nothing.
- **SC-005**: An installation without the grant shows no behavioural diff before and after.

## Out of scope

- **Module placement.** [#812](https://github.com/netresearch/t3x-nr-llm/issues/812) and ADR-119 own it; ADR-169 §6 is explicitly not accepted.
- **Widening the table set** beyond the three §2 names.
- **Routing policy** — #768 records it as having no referent.
- **A migration that moves existing records.** FR-007 requires the move be deliberate; building the tooling for it is its own change.
- **The tool kill switch.** `tx_nrllm_tool_state` has no TCA, so `tables_modify` cannot express a boundary on it even in principle. It stays admin-only under ADR-039.

## The TYPO3 questions

| Question | Answer |
|---|---|
| Version range | TYPO3 13.4 and 14.3, PHP 8.2–8.5 — the full matrix. `RootLevelCapability` and the DataHandler paths behave the same across it |
| Public surface | TCA changes (`exclude => true`) are configuration rather than PHP signatures, so no `@api` movement is expected. To be confirmed against `api-surface.txt` in the plan, where moving the wizard write paths onto the DataHandler could touch a signature |
| Backward compatibility | Intended and required — FR-006 |
| Security boundary | **This change is a security boundary change.** It opens three tables to non-admins that were admin-only. FR-002, FR-003 and FR-004 are the constraints; ADR-169 §2 and §7 hold the enumeration and the reasoning per excluded table |
| Installed instance must act | Only if it wants the capability: grant `tables_modify`, optionally `non_exclude_fields`, and move records onto a page. Nothing happens by upgrade alone (FR-006, FR-007) |
| Documentation | An administration page naming which permission grants what, including FR-008's warning that page storage does not scope anything at runtime; plus an upgrade note stating that nothing changes without a grant |
| Suite per requirement | Functional throughout. The permission model is what is under test, so a unit test cannot carry these assertions |
| Not in scope | Written out above |

**Nothing here records a measured value**, so the null-versus-zero obligation does not apply.

## The AI questions do not apply

The AI layer of the preset stack asks about provider capability, fallback, what leaves the instance, untrusted model output, per-call cost, non-determinism and model drift. **None of it has purchase here**: this feature calls no model. It is a backend permission change in an extension that talks to models elsewhere.

Recorded as a stated non-applicability rather than answered emptily — and kept in the document because it is a finding about the preset stack rather than about this feature.
