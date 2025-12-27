# Architecture Decision Records (ADR)

This directory contains Architecture Decision Records for the nr_llm extension.

## What are ADRs?

ADRs are documents that capture important architectural decisions made during development, along with their context and consequences. They serve as a historical record for understanding why certain decisions were made.

## ADR Index

| ADR | Title | Status | Date |
|-----|-------|--------|------|
| [0001](0001-api-key-encryption.md) | API Key Encryption at Application Level | Accepted | 2024-12-27 |
| [0002](0002-three-level-configuration-architecture.md) | Three-Level Configuration Architecture | Accepted | 2024-12-27 |

## ADR Template

When creating new ADRs, use the following structure:

```markdown
# ADR-NNNN: Title

**Status:** Proposed | Accepted | Deprecated | Superseded
**Date:** YYYY-MM-DD
**Authors:** Name

## Context
What is the issue that we're seeing that is motivating this decision?

## Decision
What is the change that we're proposing and/or doing?

## Consequences
What becomes easier or more difficult to do because of this change?

## Alternatives Considered
What other options were considered and why were they rejected?
```

## References

- [ADR GitHub Organization](https://adr.github.io/)
- [Michael Nygard's ADR Article](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions)
