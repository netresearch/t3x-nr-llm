# nr-llm Engineering Constitution

This file is deliberately short. It states what a specification must satisfy, not how the code is written — the `AGENTS.md` hierarchy already does that, and duplicating it here would create a second source that drifts.

**Version**: 0.1.0 | **Ratified**: 2026-08-16 | **Last amended**: 2026-08-16 | **Status**: proof of concept, not yet team policy

## Core Principles

### I. The repository's own instructions are authoritative

The closest applicable `AGENTS.md` governs implementation. This constitution never restates a rule that lives there — no PHPStan level, no test-runner invocation, no TCA or XLIFF convention, no git workflow. When the two appear to disagree, `AGENTS.md` wins and this file is wrong.

### II. Existing ADRs constrain the design

`Documentation/Adr/` holds the decisions already taken. A plan that contradicts one either cites the record it supersedes or is not the plan. Touching the public surface obliges a new ADR, in the repository's own RST format and naming — `Adr<N>Description.rst`, not a Markdown file under `specs/`.

### III. Every requirement must be verifiable by a test

A requirement no test can fail on is a wish. Each acceptance criterion names the suite that proves it — unit, integration, fuzzy, functional or E2E. "Verified by inspection" is permitted only where the artefact under test is documentation.

### IV. Backward compatibility is intentional

`Tests/Unit/Api/api-surface.txt` freezes the `@api` surface. A specification that changes it says so in its own words before the plan exists, and states whether the change is additive or breaking. Discovering it at the point the test fails is too late.

### V. Security boundaries may not be weakened

Credential handling, provider input and provider output have existing boundaries. A specification that touches one names it and says what the new boundary is. Silence is not a claim that nothing changed.

### VI. A measurement is distinguishable from its absence

Where a specification introduces a recorded value, it says what is stored when the value could not be measured. A missing measurement and a measured zero are different facts and must remain different in the data.

### VII. `make gate` is the final implementation gate

Not `composer ci`, which is a narrower and older set. Where a change lies outside every suite the gate runs, the specification says so explicitly rather than reporting a gate that had nothing to check.

## Scope

Spec Kit is used for work that spans layers or changes a contract: a new feature, a new provider, a new public API contract, a breaking or deprecating change, a security or credential topic, a large compatibility rework. It is not used for a bugfix, a dependency update, a small internal refactor or documentation alone. Applying it there produces Markdown, not clarity.

## Governance

This constitution binds specifications, not commits. It may be amended only together with the specification practice it describes, and an amendment that adds an implementation rule belongs in `AGENTS.md` instead.

Principle VI is the one entry here that came from a concrete defect rather than from general practice: telemetry that reports a cost of zero for a zero-priced model, indistinguishable from a provider that reported nothing (issue #770).
