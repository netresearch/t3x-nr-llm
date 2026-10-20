<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# MCP requests identify the response they accept

Decision: ADR-227. Amends the reply-conformance boundary of ADR-161.
The separate implementation must inherit the exact signed decision commit.

## Existing evidence and primary contract

A native scripted PSR-18 probe exercises the existing transport and client,
including their actual HTTP request bodies and response decoding. It runs
21 cases: 18 genuine assertion failures, zero framework errors and three
healthy controls. Initialize and two catalogue pages carry one issued session
while every request uses ID 1. Replies with wrong or absent IDs/version,
invalid result/error envelopes and an unrelated first SSE reply are accepted
or classified as valid remote errors. No deployed MCP interoperability claim
is made by this local probe.

A separate decision-challenge probe runs five cases: three genuine assertion
failures, zero framework errors and two healthy controls. Codes written as
`1.0` and `1e0` are valid integral JSON numbers, but the existing error rendering
invents code 0. An oversized SSE body can carry a valid first reply and a
second reply beyond the 2 MiB read prefix; the prefix is accepted. The controls
retain code `1` and a complete SSE body exactly at the existing byte limit.

[JSON-RPC 2.0 response and error objects](https://www.jsonrpc.org/specification#response_object)
require the protocol version, a corresponding ID and one success/error branch.
[MCP 2025-06-18 messages](https://modelcontextprotocol.io/specification/2025-06-18/basic)
add the session non-reuse rule and require object results. Generic JSON-RPC
permits method-defined result values; this decision's object requirement is
specifically for the MCP methods this client implements. The
[JSON Schema integer contract](https://json-schema.org/understanding-json-schema/reference/numeric)
uses numerical value rather than PHP runtime type: `1.0` is an integer.

## What it must do

1. Allocate a new opaque string ID for each JSON-RPC request before HTTP
   contact. A transport instance retains only a fresh random namespace,
   execution-process identity and a monotonically increasing local sequence.
   Namespace plus sequence is the wire ID. Never reset or wrap a sequence
   within its namespace; allocation failure or exhaustion produces the existing
   typed transport failure before contact. A forked process rotates its
   inherited namespace before its first request. These are bounded scalar
   fields, not a history of requests, credentials or remote sessions.
2. Within a client operation, initialize, every catalogue page and tool call
   use different IDs through the same transport. A later operation also gets
   new IDs. Notifications contain no ID and consume none. Independently
   constructed transports receive independent random namespaces. The existing
   client owns its server session per operation and does not share it across
   workers; no global database allocator, persistent reconnect session or
   mathematically global uniqueness guarantee is introduced. Random namespaces
   reduce collisions between independent instances; local sequences provide
   exact non-reuse in the operation this client owns.
3. Accept a reply only if the raw JSON root is an object, `jsonrpc` is the
   literal string `2.0`, and `id` equals the actual outbound ID with the same
   JSON type. A numeric or boolean approximation of a string ID does not
   match. Null or missing IDs cannot answer this client's valid request.
   An object contains exactly one of `result` and `error` by property presence,
   including explicit nulls. Unknown additional fields remain compatible;
   a `method` property mixed with a response branch is malformed.
4. For the implemented MCP methods, `result` is a JSON object. Distinguish
   literal `{}` from `[]` before associative conversion: an empty object still
   returns the existing empty PHP array, while a JSON list is refused.
   Do not infer a generic recursive object/list preservation promise from
   this root-envelope check. Existing nested associative result shapes stay
   compatible; no public result type changes.
5. A remote `error` must be a JSON object with a semantically integral JSON
   number code exactly representable as a PHP integer and a string message.
   Accept integral decimal/exponent forms such as `1.0` and `1e0` as code 1;
   PHP `is_int()` alone is insufficient. Reject fractions, booleans, strings,
   non-finite or out-of-range numbers. Verify the raw numeric value without
   rounding: a tiny fractional wire value must not become an accepted integer
   because the JSON decoder rounded it to a whole float. Do not clamp or
   invent defaults. A valid matched remote error retains the existing typed
   RPC error and the actual integer code in its sanitized sentence; malformed
   shapes, both branches and absent branches become the existing typed
   malformed-response error. Extra error data remains unexposed, as before.
   This is a narrow error-code validation boundary, not a generic lossless
   numeric decoder for every nested result field.
6. A complete bounded SSE answer applies the same validation to every
   response-looking frame. Existing server notifications/requests with a
   `method` and no response branch are passed over and do not count as replies.
   A response with the wrong ID is refused, even if a later frame would match;
   no multiplexed response-routing feature is added. Exactly one matching
   reply is required. A duplicate matching response, missing reply or malformed
   response-looking frame is refused rather than selecting the first one.
   Existing non-response framing behavior stays outside this bounded decision.
   A reply-bearing response must be complete within the existing 2 MiB limit.
   Refuse an oversized body or an incomplete read whose EOF cannot be
   established, even if its bounded prefix contains a valid reply. Detect
   overflow with bounded storage and at most one additional probe byte; never
   decode a truncated prefix as a complete answer. A complete body exactly at
   the limit stays valid. Notification response bodies remain discarded under
   the existing notification contract; this does not invent replies for them.
7. Thread the expected ID only through private decoding/framing calls. Keep
   the existing public method signatures, positional cancellation/auth context,
   return shapes and API snapshot. ID bookkeeping must not select credentials,
   retain server sessions or add token-bearing logs. Existing Vault host policy,
   redirect refusal, body bound, operation deadline and cancellation semantics
   remain authoritative; no raw HTTP or authentication fallback is introduced.
8. Keep malformed-reply diagnostics bounded and sanitized. Describe the failed
   rule with local text instead of echoing response bodies, IDs, result fields
   or arbitrary server exception messages. A refused reply is not a successful
   contact: callers must not record health or reconcile a catalogue from it.
   Valid remote error messages retain the existing sanitized rendering contract.

## Compatibility and explicit limits

This intentionally refuses replies formerly accepted despite missing or wrong
version/ID, ambiguous branches, JSON-array MCP results or malformed error
objects. It changes reply validation, not server permissions or offered tools.
A server must echo the client's actual opaque string ID. Existing protocol
fixtures must echo the recorded request for healthy responses; raw malformed
fixtures must remain literal, so a helper cannot repair the tested defect.

No stdio, resources/prompts/sampling, live stream, server-driven capability,
reconnect/resume transport, batch requests, recursive JSON-kind preservation,
new credential mode, public API or database schema is introduced. This closes
one concrete conformance gap and does not certify the whole MCP family.

## Which suite proves each requirement

| Requirement | Suite and meaningful oracle |
|---|---|
| Request non-reuse and notification absence | Unit actual initialize/notification/multiple pages and tools/call; capture raw request IDs and the issued session |
| Separate operations/instances and process epoch | Unit distinct real transports/operations; bounded real child or fork control for inherited allocator state |
| Allocation refusal before contact | Unit selected namespace/allocation or exhausted-counter boundary; outbound client must remain untouched |
| Exact version/ID/type and exclusive branches | Unit raw literal JSON matrix plus matched healthy counterparts; assertions outside production catches |
| MCP object result vs empty/list arrays | Unit literal `{}` success and literal `[]`/nonempty-array refusal with object-aware decoding |
| Valid RPC error vs malformed error object | Unit exact exception code and actual integer in the sanitized message; literal integer/integral decimal/exponent healthy cases, fractional/precision/range/string/bool/null/array refusal controls |
| SSE correlation and one response | Unit actual framed bodies with notifications, one matched reply, wrong ID, duplicate response and missing reply |
| Complete bounded response | Unit valid SSE prefix followed by padding and a duplicate beyond 2 MiB must refuse; complete exactly-at-limit and smaller bodies succeed; a stream that ends reading without confirmed EOF refuses |
| Existing auth/budget/cancellation boundaries | Existing MCP transport/client and delegated conformance suites plus scoped regressions where IDs cross their legs |
| No successful health/import from invalid replies | Unit recording health client and Functional real catalogue rows/counters unchanged after a malformed final page |
| Public signatures remain unchanged | API snapshot and ADR lifecycle/reference suites |

Actual selected faults must fail assertions when reusing IDs, accepting an
unrelated ID, omitting version/exclusivity/object validation, inventing remote
error defaults, losing integral decimal/exponent codes or selecting only the
first SSE response/accepting an oversized prefix. At least one healthy
counterpart must remain green for each refusal family. Framework errors,
bootstrap failures, warnings and timeouts do not count as assertion kills.
Run the required complete `make gate` on the final frozen implementation.
