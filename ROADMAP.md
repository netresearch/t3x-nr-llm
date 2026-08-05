# Roadmap

The direction of nr_llm, ordered by priority. This is a living document: items
can move, grow, or be dropped as reality feeds back. Architectural decisions
behind each item live in `Documentation/Adr/`; anything already shipped is in
[CHANGELOG.md](CHANGELOG.md), not here. No dates — items land when they meet
the quality gate, not when a calendar says so.

## Where we are

The security-hardening arc is shipped: explicit actor context and fail-closed
authorization through the whole agent runtime, at-least-once queue delivery
with a declared tool-effect classification and a fail-closed write audit, tool
data-class enforcement on by default, agent state encrypted at rest, and an
operations dashboard with a queryable governance audit trail. The MCP
client is shipped too: operator-configured servers are imported into the one
tool registry, and origin does not change how a tool is authorised, approved
or audited — one registry, one gate, one agent loop (ADR-116).

## Next — agent runtime maturity

1. **A first-class contract for side-effecting tools — when one exists.**
   The effect classification, the write fence and the fail-closed audit are in
   place, but all 44 builtin tools read; nothing exercises the write path. The
   proposed interface, idempotency scope and preview each had no reader and no
   display, so they are deferred rather than guessed at (ADR-122). The
   machinery and a coverage test that forces a new writer to declare itself are
   waiting; the shape of the contract is a question the first writing tool
   answers.

## Toward 1.0

- **Role model and editor actions.** Distinct operational roles (system
  administration, AI management, AI operation, editing) instead of the current
  admin-centric permissions, plus a purpose-built editor action API rather
  than generic record CRUD.
- **Complete structured outputs.** Close the schema-feature gaps (enum,
  pattern, `oneOf`, full draft support) and use provider-native structured
  output where a provider offers it.
- **A public, versioned API surface.** Mark the supported surface with `@api`,
  adopt an explicit backward-compatibility policy, and add upgrade tests so
  consumer extensions can rely on it.
- **Enforced horizontal boundaries.** Shipped: `ModuleSeamTest` asserts that
  specialized services and the tool/agent module do not depend on each other,
  that guardrails depend on neither, that nothing outside the backend package
  depends on it, and that core does not depend on the tool module — six named
  classes excepted as that module's own surface in shared directories, each
  recorded with the package it moves to in a split.
- **Re-evaluate a package split only after 1.0.** The seams from the runtime
  decomposition are the prerequisite; until they are stable, one package
  (ADR-090) stays the right call.

## Non-goals

Things nr_llm deliberately does not do, so the scope stays sharp:

- **No general-purpose MCP server.** The direction matters: nr_llm *consumes*
  MCP servers as a client and aggregates their tools (shipped — see "Where we
  are" and ADR-116), but it
  does not *expose* TYPO3 as an MCP server to third-party clients. That is a
  different product with a different security model.
- **No backend coding agent.** The agent runtime automates editorial and
  operational work against declared tools — it does not write or deploy code.
- **No further generic read-everything tools.** Tools stay purpose-built and
  data-classified; broad generic accessors undermine the data-class gate.
- **No multi-agent orchestration.** One run, one agent, one auditable
  transcript. Composition happens above nr_llm, not inside it.
- **No package split before 1.0** (ADR-090).
