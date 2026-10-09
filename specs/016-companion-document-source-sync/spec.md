<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Optional companion document source synchronisation

ADR-218 is the nr-llm decision. Implementation belongs to existing
`netresearch/nr-ai-search`, companion ADR-036 and
`specs/001-directory-source-sync/spec.md`. This specification is the
cross-package acceptance boundary, not a second indexing implementation.

## Objective and compatibility

Synchronise an administrator-configured public local document directory
through nr-ai-search's existing chunks, embeddings, persistent index and
retrieval. nr-llm supplies stateless embeddings and its existing provider
middleware. No nr-llm API signature, dependency, supported PHP range,
database schema or lexical site-search behaviour changes.

The installed companion requires PHP >=8.3 and TYPO3 13.4/14.3; nr-llm alone
remains usable across its existing matrix. The companion consumes public
nr-llm services only and attributes indexing calls as `nr_ai_search`.

## Required working behaviour

1. A configured local source has a stable identifier, root, site,
   language and citation base URL. Sources are administrator deployment
   configuration, never model-supplied roots or arbitrary URLs.
2. UTF-8 TXT/Markdown files require adjacent `<filename>.acl.json`
   containing exactly `{"access":"public"}`. Missing, malformed or
   nonpublic markers prevent provider egress and withdraw older versions.
3. A stable document identity and a content/metadata fingerprint distinguish
   unchanged, changed and removed source items. No embeddings run for an
   unchanged fingerprint. Configuration/model changes invalidate revision
   reuse where they affect vectors or citations. Identity includes the
   effective provider endpoint/configuration/model and versioned effective
   chunking parameters; a bare model name is not sufficient.
4. A changed file stages a separate generation using existing ingestion
   services. The manifest publishes the new generation only after success;
   failures keep the prior published generation readable. Retrieval
   excludes staging and obsolete generations before reranker egress;
   cached grounded answers recheck directory citations before reuse.
5. A successful inventory removes absent documents. Failed traversal/read
   never infers deletion from missing items. Explicit ACL withdrawal is
   independently enforced before content is embedded or retrieved.
6. Removal or ACL withdrawal tombstones publication before vector cleanup.
   Retries are repeatable and cannot revive a tombstone through stale work.
7. A real CLI sync and a Messenger handler execute the lifecycle. Status
   reports counts and bounded error classes, never document text, secrets
   or roots. Disable/remove the connector configuration fails closed.
8. A self-contained local fixture exercises real persistence and pipeline
   wiring with deterministic embeddings. A contract-only stub is not done.

## Boundaries

- Keep persistent source state and generation publication in nr-ai-search.
- Directory sync requires stable effective embedding provenance and rejects
  embedding fallback configurations before staging until embedding responses
  expose the actual provider/configuration identity. This restriction applies
  to the new connector; existing companion indexing remains available.
- Reuse nr-ai-search chunking/indexing and nr-llm public embedding services.
- Refuse symlinks, root escapes, malformed UTF-8 and oversized files before
  provider egress. A read failure makes that inventory incomplete.
- Public access means approval for sending document text to the configured
  embedding provider; markers are not an authorization model for private
  corpora. Do not claim multitenant isolation.
- No remote connector, PDF pipeline, endpoint, new package or bespoke broker.

## Verification

| Acceptance | Suite and artifact |
|---|---|
| Optional dependency remains one-way; core API unchanged | nr-llm API/architecture/unit gates; companion DI functional test |
| Files, revision fingerprint, ACL/path/UTF-8/size boundary | companion `DirectorySourceConnectorTest` unit tests |
| Update/no-op/deletion/failed-scan and generation lifecycle | companion `DirectorySourceSyncTest` functional tests |
| Partial store failure, late or duplicate work, revoked/staged vectors | companion publication and retry unit/functional tests |
| Provider identity/chunking change invalidates revision; denied text never reaches reranker or cached-answer reuse | companion revision, retrieval and cache unit/functional tests |
| Actual scan → chunks → embeddings → retrieval → update → delete | companion `DirectorySourceSyncTest` functional fixture |
| Real CLI and Messenger routing, safe status | companion command unit and DI/routing functional tests |

nr-llm documentation checks and `make gate` do not execute the companion.
Companion checks run independently through `Build/Scripts/runTests.sh`:
`-s unit`, `-s functional -d sqlite`, `-s phpstan`, `-s cgl -n`,
`-s rector -n`, and lint, with its PHP 8.3/8.4 CI matrix. Record executed
checks and external-state limitations separately.
