# Roadmap

What is not built yet, ordered by priority. Nothing shipped appears here:
released work is in [CHANGELOG.md](CHANGELOG.md) and the decisions behind it in
`Documentation/Adr/`. No dates — items land when they meet the quality gate.

Every item in the two sections below is an open GitHub issue. If a line there
has no issue, it is not roadmap, it is a wish. The last two sections are not
roadmap: one points at a decision recorded elsewhere, the other lists what this
project does not do.

## Known gaps

Decisions that were deliberately not built. An accepted ADR that declines to
build something closes the decision, not the gap — these stay open until code
closes them, and carry the [`deferred`](https://github.com/netresearch/t3x-nr-llm/issues?q=is%3Aissue+is%3Aopen+label%3Adeferred)
label.

- **[#688](https://github.com/netresearch/t3x-nr-llm/issues/688) — generic paths bind no context window.**
  `LlmServiceManager`'s chat, completion and streaming paths inject skills and
  send; only `ConversationService` and `ToolLoopService` bind a window. A long
  transcript through the generic API is bounded by the provider, not by us
  (ADR-139).
- **[#689](https://github.com/netresearch/t3x-nr-llm/issues/689) — injected context has no trust-zone ceiling.**
  Tool output is data-classified; skills, snippets, system prompt and task
  input are not. They pass the mandatory input guardrail, so this is a missing
  *classification*, not a missing check (ADR-139, ADR-094).
- **[#690](https://github.com/netresearch/t3x-nr-llm/issues/690) — input-resume authorises the submitter against nothing.**
  Unreachable today because no tool implements `RequiresInputInterface`, and
  pinned by a coverage test that fails when one does. The gate and the turn
  digest are the open half (ADR-105, ADR-132).

## Toward 1.0

- **[#691](https://github.com/netresearch/t3x-nr-llm/issues/691) — a management grant, once a management surface exists.**
  `BackendUserGrant` holds two cases, each with an enforcement point. A third
  without a surface would be the checkbox ADR-117 had to remove (ADR-130).
- **[#692](https://github.com/netresearch/t3x-nr-llm/issues/692) — a purpose-built editor action API.**
  Not generic record CRUD: a `update_record(table, uid, fields)` tool has the
  whole TCA as its blast radius, and its arguments are model-chosen (ADR-135).
  The open question is what an editor action is as a unit.

## Decisions that live elsewhere

- **Package split.** [ADR-090](Documentation/Adr/Adr090SingleExtensionUntil10.rst)
  decides it: one extension until 1.0, split re-evaluated **with or before**
  the 1.0 release, against the criteria listed there. The README repeats the
  timing where it explains the anticipated seams, and must keep matching
  ADR-090. Nothing else states it — a roadmap and an ADR that disagreed, with
  the roadmap citing the ADR for the opposite of its decision, is what this
  line replaces.

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
