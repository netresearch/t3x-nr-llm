<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->

# A2A test fixtures

## `a2a.v1.0.1.proto`

`specification/a2a.proto` of [a2aproject/A2A](https://github.com/a2aproject/A2A) at tag `v1.0.1`,
unchanged. It is the normative definition of A2A 1.0 (the specification's §1.4) and the source
of truth for spec 051 and ADR-278. Licence: Apache-2.0, as the upstream repository states; the
file is test data and is not part of the extension's runtime.

sha256 `e195bf96ab630c69797851970203e1b2b6b19528f2e9803b7d904b91a5104016`, asserted by
`Tests/Unit/Service/Agent/A2a/Protocol/A2aProtoFixtureTest.php`. Do not edit it: a newer
protocol version is a new file and a decision of its own.

## `Wire/*.json`

Responses of `a2a-python` (a2a-sdk 1.3.0) recorded verbatim in the stage-0 spike of
2026-11-10 (branch `spike/a2a-stage0`, `transcripts/raw-vs-py.jsonl` and `sdk-vs-py.jsonl`).
Each file holds the JSON-RPC id of the request (`requestId`), the HTTP status and the response
body as a string, exactly as it arrived. `send-v11-fields.json` is the spike's hand-written
server answering with fields of A2A v1.1 (`generation`, `status.progressHint`), which a 1.0
client must ignore.
