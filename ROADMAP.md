# Roadmap

The direction of nr_llm, ordered by priority. This is a living document: items
can move, grow, or be dropped as reality feeds back. Architectural decisions
behind each item live in `Documentation/Adr/`; anything already shipped is in
[CHANGELOG.md](CHANGELOG.md), not here. No dates — items land when they meet
the quality gate, not when a calendar says so.

## Where we are

The security-hardening arc is shipped: explicit actor context and fail-closed
authorization through the whole agent runtime, at-least-once queue delivery
with idempotent tool effects, tool data-class enforcement on by default, agent
state encrypted at rest, and an operations dashboard with a queryable
governance audit trail.

## Next — agent runtime maturity

1. **Decompose the agent runtime into focused internal services.**
   `AgentRuntime` has grown through the hardening arc; split it along its
   natural seams (authorization, request codec, queue coordination, lease
   management, resume coordination, run execution, failure recovery, outcome
   mapping) while staying one package (ADR-090). Behaviour-preserving; makes
   the runtime testable per concern and safe to extend.
2. **Make the tool loop's security collaborators mandatory.** The
   `ToolLoopService` gates (tool-call policy, per-configuration allow-list,
   input schema validation) are optional constructor arguments today — absent
   wiring silently weakens the gate. Make them required, and give tests an
   explicit lean wiring (`Null*` implementations plus a `Testing` builder)
   instead of implicit absence. A container test pins the production wiring.
3. **Conversation-level context-window management.** The tool loop already
   bounds its transcript (ADR-107); conversations do not. Plan against the
   smallest reachable model window in the fallback chain — or re-fit when a
   fallback actually fires — so a long conversation degrades predictably
   instead of failing on the provider.
4. **A first-class contract for side-effecting tools.** Promote the tool
   effect declaration (read-only / idempotent write / non-idempotent write,
   ADR-111) into a tool-facing interface with an idempotency scope and an
   optional preview, so writing tools can be built against a contract instead
   of a convention.

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
- **Enforced horizontal boundaries.** Extend the architecture test suite with
  the missing cross-cutting rules (core independent of backend and tools,
  specialized services independent of the agent runtime, guardrails
  independent of the backend).
- **Re-evaluate a package split only after 1.0.** The seams from the runtime
  decomposition are the prerequisite; until they are stable, one package
  (ADR-090) stays the right call.

## Non-goals

Things nr_llm deliberately does not do, so the scope stays sharp:

- **No general-purpose MCP server.** nr_llm consumes and orchestrates tools;
  exposing TYPO3 as an MCP server to third-party clients is a different
  product with a different security model.
- **No backend coding agent.** The agent runtime automates editorial and
  operational work against declared tools — it does not write or deploy code.
- **No further generic read-everything tools.** Tools stay purpose-built and
  data-classified; broad generic accessors undermine the data-class gate.
- **No multi-agent orchestration.** One run, one agent, one auditable
  transcript. Composition happens above nr_llm, not inside it.
- **No package split before 1.0** (ADR-090).
