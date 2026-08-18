# Roadmap

What is not built yet, ordered by priority. Nothing shipped appears here:
released work is in [CHANGELOG.md](CHANGELOG.md) and the decisions behind it in
`Documentation/Adr/`. No dates — items land when they meet the quality gate.

Every item in the two sections below is an open GitHub issue. If a line there
has no issue, it is not roadmap, it is a wish. The converse also holds and is
the easier one to get wrong: not every open issue is roadmap. Defects belong in
the tracker, not here.

The last two sections are not roadmap: one points at a decision recorded
elsewhere, the other lists what this project does not do.

## Known gaps

Decisions that were deliberately not built. An accepted ADR that declines to
build something closes the decision, not the gap — these stay open until code
closes them. The [`deferred`](https://github.com/netresearch/t3x-nr-llm/issues?q=is%3Aissue+is%3Aopen+label%3Adeferred)
label marks issues an ADR explicitly deferred; it spans this section and
Toward 1.0 alike, so it defines neither.

None open. #731 was the last, and ADR-164 with ADR-165 closed it in code: a
run's forced sources now bind against the same trust ceiling as a
configuration's own, at assembly and again on resume.

The task input stays unclassified and does not belong here. ADR-144 declined it
for want of a per-record home the declaration could live on, and ADR-164
restates that reason where it declines to extend the ceiling to it — no code
closes it, so no issue tracks it.

## Toward 1.0

- **[#691](https://github.com/netresearch/t3x-nr-llm/issues/691) — a management grant, once a management surface exists.**
  `BackendUserGrant` holds two cases, each with an enforcement point. A third
  without a surface would be the checkbox ADR-117 had to remove (ADR-130).

## Decisions that live elsewhere

- **Package split — decided, not pending.**
  [ADR-090](Documentation/Adr/Adr090SingleExtensionUntil10.rst) scheduled the
  re-evaluation for "with or before the 1.0 release";
  [ADR-159](Documentation/Adr/Adr159OneExtensionConfirmedAtTheFreeze.rst) is
  that re-evaluation, and its outcome is that nr_llm stays one extension
  through 1.0. Of ADR-090's three extraction criteria only the API freeze is
  met: no consumer has asked for a separate install, and contract stability
  cannot honestly be claimed for any module while the complete frozen surface
  is one release old. The README repeats the timing where it explains the
  anticipated seams, and must keep matching both records.

## Non-goals

Things nr_llm deliberately does not do, so the scope stays sharp:

- **No general-purpose MCP server.** nr_llm *consumes* MCP servers as a client
  and aggregates their tools (ADR-116); it does not *expose* TYPO3 as an MCP
  server to third-party clients. That is a different product with a different
  security model.
- **No backend coding agent.** The agent runtime automates editorial and
  operational work against declared tools — it does not write or deploy code.
- **No generic read-everything or write-everything tools.** Tools stay
  purpose-built and data-classified; a broad generic accessor undermines the
  data-class gate on the read side and the review-time bound on the write side
  (ADR-135).
- **No multi-agent orchestration.** One run, one agent, one auditable
  transcript. Composition happens above nr_llm, not inside it.
- **No `$ref` in the strict schema subset.** Deliberately outside the
  supported subset, not a missing feature (ADR-126).
