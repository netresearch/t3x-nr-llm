<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Optional b13 AI Label integration

AI text writes currently set only nr-llm's boolean. Installations that use
b13/ai-label should also receive its origin and editorial-review metadata.

## Requirements

- Keep b13/ai-label optional at runtime; suggest >=1.6 and install ^1.6 for
  development tests. No changes to existing public signatures or database schema.
- Keep the existing boolean and public provenance event unchanged. Carry the
  actual acting backend user and generation classification in an internal event,
  dispatched independently so another listener's failure cannot suppress it.
- Register the bridge only when the active TYPO3 packages provide AI Label's
  API service, after all service definitions have loaded. Without the extension
  loaded, writes keep their existing behavior.
- Label pages and content elements only, when AI Label enables their table.
  Ignore deletions, unchanged text, file metadata and other tables.
- Only a complete built-in create-content-element operation that writes a text
  element with explicitly supplied, nonempty body text establishes new AI-generated content. Page creation, translations, generic tools and field
  fills establish an AI contribution, not that the entire record was generated.
  Preserve an existing Generated origin on later AI modifications.
- A subsequent AI write resets human review. Do not infer human review from a
  tool approval. Do not backfill historical records or synchronize manual changes
  of the independent boolean.
- Use AI Label's public API, the acting user and workspace, never an ambient
  user's privileges. Restore ambient user and language after hooks, including
  exceptions. In a draft workspace update the existing draft only, never live
  or a newly recreated draft. Read back the label and report failed writes.
- Failures are logged without converting a completed content write into a failed
  tool call; the original boolean still records the AI contribution.
- Document installation, origin mapping, review resets, independent markers and
  frontend setup delegated to AI Label.

## Verification

| Requirement | Evidence |
| --- | --- |
| Optional registration, installed/absent container | functional AI Label and existing marker tests; unit container configuration tests |
| Origin classification, actual user, independent dispatch | unit tool-loop write event tests; functional field-fill tests |
| Real public API, review reset, preserved origin, draft isolation, readback | functional AI Label tests against b13/ai-label 1.6 |
| Excluded operations/tables and ambient restoration | functional AI Label tests |
| No public signature changes | API surface unit test |
| Documentation | docs validation and HTML render |
| Final checks | make gate plus focused functional tests; GitHub matrix |

No new credential, provider-egress or LLM-input boundary is introduced. The bridge
performs a permission-checked metadata save after a successful content save. It
neither grants access nor claims that a missing label is a measured human origin.
