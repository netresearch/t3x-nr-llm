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
closes them. The [`deferred`](https://github.com/netresearch/t3x-nr-llm/issues?q=is%3Aissue+is%3Aopen+label%3Adeferred)
label marks issues an ADR explicitly deferred; it spans this section and
Toward 1.0 alike, so it defines neither.

- **[#690](https://github.com/netresearch/t3x-nr-llm/issues/690) — input-resume authorises the submitter against nothing.**
  Unreachable today because no tool implements `RequiresInputInterface`, and
  pinned by a coverage test that fails when one does. The gate and the turn
  digest are the open half (ADR-105, ADR-132).
- **[#723](https://github.com/netresearch/t3x-nr-llm/issues/723) — a criteria-mode configuration has no real trust zone.**
  `TrustZoneResolver` reads the configuration's provider, which a criteria-mode
  record does not have, so it resolves to `EXTERNAL_GLOBAL` even when routing
  only ever picks a local model. Fail-closed, and wrong. This is ADR-144's own
  `Revisit when`, answerable since ADR-142.
- **[#724](https://github.com/netresearch/t3x-nr-llm/issues/724) — the system prompt carries no data class.**
  ADR-144 classified snippets and skills and declined the system prompt for
  want of a consumer. Routing eligibility becomes that consumer once #723
  lands. Task input stays unclassified: it has no per-record home.

## Toward 1.0

### Explainability

- **[#718](https://github.com/netresearch/t3x-nr-llm/issues/718) — no surface answers "why this model and not that one".**
  `RoutingDecisionService` records the whole candidate field and the answer is
  discarded one line later; the service is `@internal` and unreachable from a
  controller (ADR-142, ADR-145).
- **[#719](https://github.com/netresearch/t3x-nr-llm/issues/719) — the decision is not persisted, and must not be without a reader.**
  A prompt-free summary on the per-request row, shipped together with the
  readout that reads it. ADR-142 deferred the trace on exactly that condition.
- **[#720](https://github.com/netresearch/t3x-nr-llm/issues/720) — complexity routing has no evidence.**
  Measure complexity, cost, latency and quality first; route on it only once a
  sample proves cheaper models hold. observe → measure → prove → route.
- **[#725](https://github.com/netresearch/t3x-nr-llm/issues/725) — the context budget is computed and never shown.**
  `ContextFitResult` returns one aggregate and reaches no controller, so when a
  run drops turns nobody can say what filled the window (ADR-143).

### Governance

- **[#721](https://github.com/netresearch/t3x-nr-llm/issues/721) — the simulator covers the tool gate, not the run.**
  The input-context gate and routing eligibility are not wired in, and the
  approval predicate is a private method with a duplicate. ADR-145 names the
  first two itself.
- **[#722](https://github.com/netresearch/t3x-nr-llm/issues/722) — the simulator answers for the ambient admin.**
  A rollout question is about an editor group. The resolver seam that turns an
  `AiActorContext` into the gate's backend user already exists and the
  controller does not use it (ADR-145).
- **[#691](https://github.com/netresearch/t3x-nr-llm/issues/691) — a management grant, once a management surface exists.**
  `BackendUserGrant` holds two cases, each with an enforcement point. A third
  without a surface would be the checkbox ADR-117 had to remove (ADR-130).

### Editor actions

- **[#692](https://github.com/netresearch/t3x-nr-llm/issues/692) — a purpose-built editor action API.**
  Not generic record CRUD: a `update_record(table, uid, fields)` tool has the
  whole TCA as its blast radius, and its arguments are model-chosen (ADR-135).
  Five narrow writers ship (ADR-146); what none of them can declare is a human
  label, an icon, the record types they apply to, or a preview a UI can ask for
  before a run exists.

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
