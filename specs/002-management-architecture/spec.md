# Non-admin record management

**Feature directory**: `specs/002-management-architecture` | **Created**: 2026-08-17 | **Status**: Blocked — see *Blocking decision* | **Source**: [#768](https://github.com/netresearch/t3x-nr-llm/issues/768), [#691](https://github.com/netresearch/t3x-nr-llm/issues/691) | **Record**: ADR-169 (`Proposed`)

## Overview

An editor holding the right TYPO3 permissions should be able to maintain the extension's own content — tasks, prompt snippets, skills — without an administrator. Today every such record needs an admin, and the surrounding analysis for why is already written: ADR-169 enumerates where the records may live, which of them a non-admin may touch, which write paths bypass the permission model entirely, and what the grant would have to be called.

**This specification does not repeat that analysis and must not be read as settling it.** ADR-169 carries `:Status: Proposed` and says of itself that it "recommends answers and settles none of them".

## Blocking decision

One choice determines most of the requirements below, and it has not been made:

**Where do management-owned records live?** ADR-169 §1 lays out Option R (open `security.ignoreRootLevelRestriction` and keep records at pid 0, a whole-installation switch) and Option P (store them on a page inside a web mount, which needs no core-permission change and gives page-level granularity), and recommends P with its two costs named.

Until that is decided, the following cannot be specified without inventing an answer: what an operator has to do to an existing installation, which surfaces change, and whether the boundary is expressed in TCA or in page permissions. The requirements marked **[R/P]** below are the ones that differ.

Three further decisions from the same record are open and are cited rather than pre-empted here: the exact table subset (§2), whether the two wizard write paths move onto DataHandler or their surfaces stay admin-only (§3), and whether ADR-119 is reopened for module placement (§6).

## Requirements that hold under either option

- **FR-001**: A backend user who is not an administrator MUST be able to create, edit and delete the record types the accepted decision names, without an administrator performing the action.
- **FR-002**: No record type that carries a credential or governs outbound access MAY become editable by a non-administrator as a consequence of this change. ADR-169 §2 and §7 enumerate the candidates and the reasoning; the set may only narrow relative to that, never widen, without a separate security decision.
- **FR-003**: The boundary MUST hold on **every** write path that reaches these records, not only on the one a form uses. Where a path cannot enforce it, that path's surface stays administrator-only. A boundary honoured by one path and ignored by another is worse than no boundary, because it reads as enforced.
- **FR-004**: An installation that has not opted in MUST see no behaviour change. Existing records stay reachable by the runtime exactly as before.
- **FR-005**: It MUST be visible, from the record or its context, which permission made an edit possible — so that an installation can be audited for who may change what without reading code.
- **FR-006 [R/P]**: What an operator must do to an existing installation. Under R a capability is opened once and applies everywhere including the list module; under P existing records have to be somewhere a web mount reaches. The requirement cannot name the action before the option is chosen.

## User scenarios

### Scenario 1 — an editor maintains a prompt snippet (Priority: P1)

- **Given** a backend user with the permissions the accepted decision requires and no administrator flag, **When** they open the snippet record and change its text, **Then** the change is saved and attributed to them.
- **Given** the same user, **When** they attempt to open a record type outside the permitted set, **Then** the attempt is refused, and refused by the permission model rather than by a hidden menu entry.

**Independent test**: a functional test asserting both outcomes for one non-admin user against a permitted and a forbidden table.

### Scenario 2 — the surface that bypasses the permission model (Priority: P1)

- **Given** a write path that does not go through TYPO3's record-permission checks, **When** a non-admin reaches it, **Then** either it enforces the same boundary or it is not reachable by them at all.

**Independent test**: a functional test per bypassing path, asserting a non-admin cannot use it to write a record they could not write through the checked path. This is the assertion that would have caught the gap ADR-169 §3 describes.

### Scenario 3 — an installation that did not ask for this (Priority: P2)

- **Given** an installation that has not opted in, **When** it is upgraded, **Then** no non-admin gains an ability they did not have, and no existing record becomes unreachable.

## Success criteria

- **SC-001**: A named editor role can complete the maintenance work on the permitted record types with no administrator involved, measured by doing it.
- **SC-002**: For every write path reaching a permitted record type, a test asserts the boundary. The count of paths without such a test is zero — that count, not "the tests pass", is the criterion.
- **SC-003**: No credential-bearing or egress-governing record type is editable by a non-administrator, asserted rather than reviewed.
- **SC-004**: An installation that has not opted in shows no diff in behaviour.

## Out of scope

- Deciding Option R versus Option P. That is ADR-169's to settle and a human's to accept; this document is blocked on it, not competing with it.
- Routing policy. #768 records it as having no referent and it stays out.
- Widening the record-type set beyond what the accepted decision names.
- Module placement. ADR-119 governs it and reopening it is its own change.

## The TYPO3 questions, answered where answerable

| Question | Answer |
|---|---|
| Version range | TYPO3 13.4 and 14.3, PHP 8.2 through 8.5 — the project's full matrix; nothing here is version-specific |
| Public surface | **Not answerable yet.** Under R a table capability changes; under P the change may be confined to storage and permissions. Whether an `@api` signature moves depends on how the boundary is expressed, which is decision §4 |
| Backward compatibility | Intended, and FR-004 states it: an installation that has not opted in sees nothing |
| Security boundary | This change **is** a security boundary change — that is its whole content, not a side effect. FR-002 and FR-003 are the constraints; ADR-169 §2 and §7 hold the enumeration |
| Installed instance must act | **[R/P]** — see FR-006 |
| Documentation | An administration page describing which permissions grant what, and the upgrade note. Named once the option is chosen, because the two options need different pages |
| Suite per requirement | Functional for FR-001 to FR-005; the boundary assertions cannot be unit tests because the permission model is what is under test |
| Not in scope | Written out above |

**Nothing here records a measured value**, so the null-versus-zero obligation does not apply to this feature.

## The AI questions do not apply

The AI layer of the preset stack asks about provider capability, fallback, what leaves the instance, untrusted model output, per-call cost and non-determinism. **None of it has purchase here**: this feature calls no model. It is a backend permission change in an extension that happens to talk to models elsewhere.

Recording that rather than answering the questions emptily, because an empty answer to a security-shaped question is worse than a stated non-applicability — and because it is a finding about the preset stack, not about this feature.
