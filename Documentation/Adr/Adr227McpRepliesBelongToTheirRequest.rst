.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-227:

============================================================
ADR-227: MCP replies belong to the request that accepts them
============================================================

:Status: Accepted
:Date: 2026-10-11
:Amends: :ref:`ADR-161 <adr-161>` and :ref:`ADR-181 <adr-181>`
    (reply selection and completeness)

Context
=======

The client sends ``id: 1`` for initialize and every catalogue page in the same
issued session. Decoding accepts a result without checking its version or ID,
and the finite SSE parser chooses the first response-looking frame. A native
scripted-wire probe has eighteen genuine assertion failures and three healthy
controls; these failures demonstrate actual request/response behavior without
claiming live interoperability. A separate five-case decision challenge adds
three assertion failures and two healthy controls: integral JSON error codes
``1.0`` and ``1e0`` are rendered as 0, and a valid SSE prefix hides a second
reply beyond the bounded read. The original probe remains separate evidence.

The `JSON-RPC response contract <https://www.jsonrpc.org/specification#response_object>`_
requires correlation and exclusive result/error branches. The
`MCP message contract <https://modelcontextprotocol.io/specification/2025-06-18/basic>`_
also requires object results and request-ID non-reuse within a session.

Decision
========

1. Allocate opaque string request IDs from a transport-local random namespace
   and increasing sequence. Detect a changed execution process before reusing
   an inherited allocator, and refuse allocation failure or exhaustion before
   HTTP contact. The client keeps its existing per-operation session ownership.
   This retains bounded scalar bookkeeping, rather than a global allocator or
   a persistent cross-worker session. Independent random namespaces do not
   create a mathematical global uniqueness guarantee.
2. Validate the raw response object, exact ``2.0`` version, matching ID/type
   and property-presence exclusivity before converting MCP object results into
   the existing PHP arrays. A valid remote error remains a typed RPC failure;
   malformed wire shapes become the existing malformed-response exception.
   Do not replace absent/malformed remote fields with invented defaults.
   Error codes follow semantic JSON integer value: integral decimal/exponent
   forms retain their exactly representable PHP integer value. Refuse fractions,
   range overflow and values only made integral by decoder rounding. This is
   a narrow error-code check, not generic lossless nested numeric decoding.
3. Apply that boundary to every response-looking frame of the existing finite,
   bounded SSE answer. Skip server notifications/requests, refuse unrelated
   responses and duplicates, and require exactly one matching response. This
   adds validation, not multiplexed routing or live stream support. Establish
   that a reply-bearing body is complete within 2 MiB; refuse overflow or an
   incomplete read instead of accepting a valid truncated prefix. A bounded
   one-byte overflow/EOF probe permits a complete body exactly at the limit.
   Notification response bodies keep their existing discarded-body contract.
4. Preserve public signatures, cancellation and auth context positions, Vault
   outbound policy, deadline and body bounds. Malformed-reply details use local
   bounded text and expose no raw response fields. A refused operation records
   neither successful health nor a reconciled catalogue.

The precise requirements and suite/fault oracles are in
:file:`specs/025-mcp-response-correlation/spec.md`. Publish this decision first;
its implementation is a separate PR on the exact signed decision commit.

Alternatives considered
=======================

A constant ID per HTTP connection does not satisfy the server session's
non-reuse rule. Permissive decoding cannot distinguish a requested answer from
an unrelated one. Routing arbitrary concurrent responses would expand the
transport into a different feature; the current finite operation instead
refuses an unrelated response. Keeping every allocated ID in memory or using a
database counter would add unbounded or shared state without a supported
cross-worker session that requires it.

Consequences
============

Malformed replies that used to pass will be refused, including absent/wrong
IDs or versions and JSON-array MCP results. Healthy fixtures must echo their
actual request ID while raw refusal cases remain literal. Root object checks
leave nested associative decoding unchanged and do not promise recursive JSON
kind preservation. Existing credential, approval, classification and audit
contracts remain authoritative. This is a bounded conformance repair, not a
whole-MCP certification.
